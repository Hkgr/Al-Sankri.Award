<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAccess;
use App\Admin\AdminCycles;
use App\Admin\ApplicationReviews;
use App\Admin\AuditTrail;
use App\Admin\JudgingCommittees;
use App\Admin\RegisteredUsers;
use App\Admin\SubmittedApplications;
use App\Admin\SystemLogs;
use App\Candidates\NominationTaxonomy;
use App\Http\Controllers\Controller;
use App\Reports\ApplicationCard;
use App\Reports\ExportLimits;
use App\Reports\Formats;
use App\Reports\PdfWriter;
use App\Reports\TableReport;
use App\Reports\XlsxWriter;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads of the administration's reports (reports.export plus the
 * source module's own viewing permission, checked here):
 * - a table (users, applications, committee members, judge scores, the
 *   activity log, technical-log summaries) as XLSX or PDF, with ALL rows
 *   matching the table's current filters, search, sort and cycle; the same
 *   services, validation and fields as the table; above ExportLimits the
 *   export is refused, never truncated;
 * - «بطاقة الترشح» of one submitted application as PDF.
 *
 * Files are built inside the request: PDFs in memory, XLSX in a private
 * temporary file deleted once sent. Every download is recorded once in
 * audit_logs (report.exported) with its real cycle when there is one.
 */
class ExportsController extends Controller
{
    private const TABLES = [
        'users' => AdminAccess::VIEW_USERS,
        'applications' => AdminAccess::VIEW_APPLICATIONS,
        'committee-members' => AdminAccess::MANAGE_COMMITTEES,
        'judge-scores' => AdminAccess::VIEW_APPLICATIONS,
        'audit' => AdminAccess::VIEW_AUDIT,
        'system-logs' => AdminAccess::VIEW_SYSTEM_LOGS,
    ];

    public function __construct(private readonly AdminAccess $access, private readonly AdminCycles $cycles) {}

    public function table(Request $request, string $table): Response|BinaryFileResponse|JsonResponse
    {
        if (! isset(self::TABLES[$table])) {
            return response()->json(['code' => 'not_found', 'message' => 'هذا التقرير غير موجود.'], 404);
        }
        if (! $this->access->can($request->user(), self::TABLES[$table])) {
            return response()->json(['code' => 'not_admin', 'message' => 'لا يملك حسابك صلاحية عرض هذا الجدول، فلا يمكنه تصديره.'], 403);
        }

        $format = (string) $request->validate(['format' => ['required', Rule::in(ExportLimits::FORMATS)]], ['format.*' => 'اختر صيغة التصدير.'])['format'];
        $limit = ExportLimits::for($format);

        $built = match ($table) {
            'users' => $this->users($request, $limit),
            'applications' => $this->applications($request, $limit),
            'committee-members' => $this->committeeMembers($request),
            'judge-scores' => $this->judgeScores($request),
            'audit' => $this->audit($request, $limit),
            'system-logs' => $this->systemLogs($request, $limit),
        };
        if ($built instanceof JsonResponse) {
            return $built;
        }
        [$report, $cycleId] = $built;
        if (count($report->rows) > $limit) {
            return $this->tooLarge(count($report->rows), $limit, $format);
        }

        // The event records a file the writer finished generating (it is not
        // proof that the browser received or saved it): a failed rendering
        // or file write records nothing.
        $record = fn () => AuditLog::record('report.exported', (int) $request->user()->getKey(), null, null, [
            'report' => $report->code, 'format' => $format, 'rows' => count($report->rows),
        ], $cycleId);

        if ($format === 'pdf') {
            $bytes = app(PdfWriter::class)->table($report);
            $record();

            return $this->pdf($bytes, $report->code);
        }

        $path = $this->writeXlsx($report);
        try {
            $record();
        } catch (\Throwable $exception) {
            File::delete($path);

            throw $exception;
        }

        return response()->download($path, Formats::filename($report->code, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend();
    }

    /**
     * «بطاقة الترشح»: one submitted application as the detail page shows it.
     */
    public function applicationCard(Request $request, int $application, SubmittedApplications $applications): Response|JsonResponse
    {
        if (! $this->access->can($request->user(), AdminAccess::EXPORT_REPORTS)) {
            return response()->json(['code' => 'not_admin', 'message' => 'لا يملك حسابك صلاحية التصدير.'], 403);
        }
        $detail = $applications->show($application);
        if ($detail === null) {
            return response()->json(['code' => 'not_found', 'message' => 'لم يُعثر على ملف ترشح مرسل بهذا الرقم.'], 404);
        }

        $generated = now();
        $pdf = app(PdfWriter::class)->application(ApplicationCard::build($detail), Formats::dateTime($generated->toIso8601ZuluString()));
        AuditLog::record('report.exported', (int) $request->user()->getKey(), 'application', $application, [
            'report' => 'application_card', 'format' => 'pdf', 'reference_number' => $detail['summary']['reference_number'],
        ], (int) DB::table('applications')->where('id', $application)->value('cycle_id'));

        return $this->pdf($pdf, 'application-card-'.Str::slug((string) $detail['summary']['reference_number']));
    }

    /**
     * @return array{0: TableReport, 1: int|null}|JsonResponse
     */
    private function users(Request $request, int $limit): array|JsonResponse
    {
        $filters = $request->validate(RegisteredUsers::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات الجدول.']);
        $users = app(RegisteredUsers::class);
        $cycleId = $users->cycleFor($filters, $this->cycles);
        if ($cycleId === false) {
            return response()->json(['code' => 'cycle_not_found', 'message' => 'الدورة المختارة غير موجودة.'], 404);
        }
        if (($total = $users->count($filters, $cycleId)) > $limit) {
            return $this->tooLarge($total, $limit, (string) $request->query('format'));
        }

        $export = $users->export($filters, $cycleId, false);
        $stage = ['complete' => 'مكتملة', 'incomplete' => 'غير مكتملة', 'none' => 'لم تُحفظ'];
        $rows = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'email' => $row['email'],
            'verified' => $row['email_verified'] ? 'مؤكَّد' : 'غير مؤكَّد',
            'roles' => implode('، ', [
                ...array_column($row['roles']['platform'], 'name'),
                ...array_map(fn (array $role): string => "{$role['name']} ({$role['cycle']['name']} — {$role['cycle']['year']}".($role['domain'] ? " · {$role['domain']}" : '').')', $row['roles']['cycle']),
            ]) ?: 'لا أدوار حالية',
            'state' => $row['active'] ? 'نشط' : 'معطّل',
            'registered' => Formats::dateTime($row['registered_at']),
            'last_login' => $row['last_login_at'] ? Formats::dateTime($row['last_login_at']) : 'لم يسجّل دخولاً',
            'progress' => RegisteredUsers::PROGRESS_LABELS[$row['progress']['state']] ?? $row['progress']['state'],
            'stage1' => $stage[$row['progress']['stage1']],
            'stage2' => $stage[$row['progress']['stage2']],
            'stage3' => $stage[$row['progress']['stage3']],
            'submission' => $row['submission'] ? 'أُرسل · '.$row['submission']['reference_number'].' · '.Formats::dateTime($row['submission']['submitted_at']).' · '.(ApplicationReviews::LABELS[$row['submission']['review']] ?? '') : ($row['progress']['state'] === 'not_required' ? '—' : 'لم يُرسل'),
        ], $export['data']);

        $cycle = $export['cycle'];
        $scope = [['تقدّم الترشح في', $cycle ? "{$cycle['name']} — {$cycle['year']}" : 'لا توجد دورة (المرحلة 1 فقط)']];
        $scope = [...$scope, ...$this->described($filters, [
            'q' => 'البحث',
            'role' => ['الدور', ['candidate' => 'مترشح', 'award_admin' => 'مدير الجائزة', 'judge' => 'حكَم في الدورة المختارة']],
            'state' => ['حالة الحساب', ['active' => 'نشط', 'disabled' => 'معطّل']],
            'verified' => ['تأكيد البريد', ['verified' => 'مؤكَّد', 'unverified' => 'غير مؤكَّد']],
            'progress' => ['تقدّم الترشح', RegisteredUsers::PROGRESS_LABELS],
        ]), $this->sortDescription($filters, ['name' => 'الاسم', 'registered_at' => 'تاريخ التسجيل', 'last_login_at' => 'آخر تسجيل دخول'], 'registered_at', 'desc')];

        return [new TableReport('users', 'المستخدمون', [
            ['key' => 'name', 'label' => 'الاسم', 'width' => 24], ['key' => 'email', 'label' => 'البريد الإلكتروني', 'width' => 30],
            ['key' => 'verified', 'label' => 'تأكيد البريد', 'width' => 12], ['key' => 'roles', 'label' => 'الأدوار الحالية', 'width' => 30],
            ['key' => 'state', 'label' => 'الحساب', 'width' => 10], ['key' => 'registered', 'label' => 'تاريخ التسجيل', 'width' => 22],
            ['key' => 'last_login', 'label' => 'آخر تسجيل دخول', 'width' => 22], ['key' => 'progress', 'label' => 'تقدّم الترشح', 'width' => 28],
            ['key' => 'stage1', 'label' => 'المرحلة 1', 'width' => 12], ['key' => 'stage2', 'label' => 'المرحلة 2', 'width' => 12],
            ['key' => 'stage3', 'label' => 'المرحلة 3', 'width' => 12], ['key' => 'submission', 'label' => 'الإرسال النهائي', 'width' => 34],
        ], $rows, $scope, now()), $cycle['id'] ?? null];
    }

    /**
     * @return array{0: TableReport, 1: int|null}|JsonResponse
     */
    private function applications(Request $request, int $limit): array|JsonResponse
    {
        $filters = $request->validate(SubmittedApplications::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات الجدول.']);
        $applications = app(SubmittedApplications::class);
        if (($total = $applications->count($filters)) > $limit) {
            return $this->tooLarge($total, $limit, (string) $request->query('format'));
        }

        $rows = array_map(fn (array $row): array => [
            'reference' => $row['reference_number'],
            'title' => $row['title'],
            'applicant' => $row['applicant_name'],
            'type' => $row['applicant_type'] === 'individual' ? 'فرد' : 'مؤسسة',
            'domain' => $row['domain']['name'],
            'subcategory' => $row['subcategory']['name'] ?? '—',
            'submitted' => Formats::dateTime($row['submitted_at']),
            'status' => ApplicationCard::STATUS_LABELS[$row['status']] ?? $row['status'],
            'review' => ApplicationReviews::LABELS[$row['review']] ?? $row['review'],
            'attachments' => (int) $row['attachment_count'],
        ], $applications->export($filters));

        $cycleId = isset($filters['cycle']) ? (int) $filters['cycle'] : null;
        $cycle = $cycleId === null ? null : collect($this->cycles->context()['cycles'])->firstWhere('id', $cycleId);
        $domains = collect(NominationTaxonomy::DOMAINS)->map(fn (array $domain): string => $domain['name'])->all();
        $scope = [['الدورة', $cycle ? "{$cycle['name']} — {$cycle['year']}" : 'كل الدورات'], ...$this->described($filters, [
            'domain' => ['المجال الرئيسي', $domains],
            'subcategory' => 'الفئة الفرعية',
            'applicant_type' => ['نوع المترشح', ['individual' => 'فرد', 'institution' => 'مؤسسة']],
            'status' => ['الحالة', ApplicationCard::STATUS_LABELS],
            'review' => ['التصنيف الإداري', ApplicationReviews::LABELS],
            'q' => 'البحث',
        ]), $this->sortDescription($filters, ['submitted_at' => 'وقت الإرسال', 'reference' => 'الرقم المرجعي', 'title' => 'عنوان العمل', 'applicant' => 'المترشح', 'status' => 'الحالة'], 'submitted_at', 'desc')];

        return [new TableReport('applications', 'ملفات الترشح', [
            ['key' => 'reference', 'label' => 'الرقم المرجعي', 'width' => 18], ['key' => 'title', 'label' => 'عنوان العمل', 'width' => 36],
            ['key' => 'applicant', 'label' => 'المترشح أو المؤسسة', 'width' => 26], ['key' => 'type', 'label' => 'النوع', 'width' => 10],
            ['key' => 'domain', 'label' => 'المجال الرئيسي', 'width' => 16], ['key' => 'subcategory', 'label' => 'الفئة الفرعية', 'width' => 18],
            ['key' => 'submitted', 'label' => 'وقت الإرسال', 'width' => 22], ['key' => 'status', 'label' => 'الحالة', 'width' => 16],
            ['key' => 'review', 'label' => 'التصنيف الإداري', 'width' => 18], ['key' => 'attachments', 'label' => 'المرفقات', 'width' => 10],
        ], $rows, $scope, now()), $cycleId];
    }

    /**
     * @return array{0: TableReport, 1: int|null}|JsonResponse
     */
    private function committeeMembers(Request $request): array|JsonResponse
    {
        $validated = $request->validate([
            'cycle' => ['required', 'integer', 'min:1'],
            'domain' => ['required', Rule::in(NominationTaxonomy::domainCodes())],
        ], ['*' => 'اختر الدورة والمجال الرئيسي.']);
        $cycleId = (int) $validated['cycle'];
        $cycle = collect($this->cycles->context()['cycles'])->firstWhere('id', $cycleId);
        $entry = $cycle === null ? null : collect(app(JudgingCommittees::class)->overview($cycleId))->firstWhere('domain.code', $validated['domain']);
        if ($entry === null || $entry['committee'] === null) {
            return response()->json(['code' => 'not_found', 'message' => 'لا توجد لجنة تحكيم لهذا المجال في الدورة المختارة.'], 404);
        }

        $committee = $entry['committee'];
        $rows = array_map(fn (array $member): array => [
            'name' => $member['name'],
            'email' => $member['email'],
            'phone' => $member['phone'] ?? '—',
            'specialty' => $member['specialty'] ?? '—',
            'domain' => $member['domain'],
            'joined' => Formats::dateTime($member['joined_at']),
            'account' => implode('، ', array_filter([
                $member['account']['active'] ? 'الحساب نشط' : 'الحساب معطّل',
                $member['account']['setup_pending'] ? 'بانتظار تعيين كلمة المرور' : null,
                $member['account']['email_verified'] ? null : 'البريد غير مؤكَّد',
            ])),
        ], $committee['members']);

        return [new TableReport('committee-members', "أعضاء {$committee['name']}", [
            ['key' => 'name', 'label' => 'الاسم', 'width' => 26], ['key' => 'email', 'label' => 'البريد الإلكتروني', 'width' => 30],
            ['key' => 'phone', 'label' => 'الهاتف', 'width' => 18], ['key' => 'specialty', 'label' => 'التخصص', 'width' => 22],
            ['key' => 'domain', 'label' => 'المجال الرئيسي', 'width' => 16], ['key' => 'joined', 'label' => 'عضو منذ', 'width' => 22],
            ['key' => 'account', 'label' => 'حالة الحساب', 'width' => 30],
        ], $rows, [
            ['الدورة', "{$cycle['name']} — {$cycle['year']}"], ['المجال الرئيسي', $entry['domain']['name']],
            ['حالة اللجنة', $committee['status'] === 'approved' ? 'معتمدة' : 'مسودة'], ['الأعضاء', 'الأعضاء الحاليون فقط'],
        ], now()), $cycleId];
    }

    /**
     * @return array{0: TableReport, 1: int|null}|JsonResponse
     */
    private function judgeScores(Request $request): array|JsonResponse
    {
        $validated = $request->validate(['application' => ['required', 'integer', 'min:1']], ['*' => 'حدّد ملف الترشح.']);
        $detail = app(SubmittedApplications::class)->show((int) $validated['application']);
        if ($detail === null) {
            return response()->json(['code' => 'not_found', 'message' => 'لم يُعثر على ملف ترشح مرسل بهذا الرقم.'], 404);
        }

        $judging = $detail['judging'];
        $rows = array_map(fn (array $score): array => [
            'judge' => $score['name'],
            'score' => $score['score'] === null ? 'بانتظار الإرسال' : "{$score['score']} من 100",
            'excluded' => ApplicationCard::exclusion($score),
            'notes' => $score['notes'] ?? '—',
            'recommendations' => $score['recommendations'] ?? '—',
            'sent' => $score['sent_at'] ? Formats::dateTime($score['sent_at']) : '—',
        ], $judging['scores'] ?? []);

        return [new TableReport('judge-scores', 'تقييمات الحكام', [
            ['key' => 'judge', 'label' => 'الحكَم', 'width' => 26], ['key' => 'score', 'label' => 'الدرجة', 'width' => 16],
            ['key' => 'excluded', 'label' => 'الاستبعاد', 'width' => 18], ['key' => 'notes', 'label' => 'الملاحظات', 'width' => 45],
            ['key' => 'recommendations', 'label' => 'التوصيات', 'width' => 45], ['key' => 'sent', 'label' => 'وقت الإرسال', 'width' => 22],
        ], $rows, [
            ['ملف الترشح', (string) $detail['summary']['reference_number']],
            ['النتيجة', ! ($judging['ready'] ?? false) ? (($judging['referred'] ?? false) ? 'لم تُعتمد لجنة تحكيم هذا المحور بعد' : 'لم يُرسل هذا الملف إلى التحكيم') : ($judging['decision'] !== null ? "متوسط التقييمات {$judging['decision']} من 100" : "أُرسل {$judging['received']} من {$judging['expected']}")],
            ['الحالة', 'حالة التحكيم وقت التصدير'],
        ], now()), (int) DB::table('applications')->where('id', $detail['summary']['id'])->value('cycle_id')];
    }

    /**
     * @return array{0: TableReport, 1: int|null}|JsonResponse
     */
    private function audit(Request $request, int $limit): array|JsonResponse
    {
        $filters = $request->validate(AuditTrail::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات السجل.']);
        $trail = app(AuditTrail::class);
        if (($total = $trail->count($filters)) > $limit) {
            return $this->tooLarge($total, $limit, (string) $request->query('format'));
        }

        $rows = array_map(fn (array $row): array => [
            'time' => Formats::dateTime($row['occurred_at']),
            'actor' => $row['actor'] ? "{$row['actor']['name']} ({$row['actor']['email']})" : 'النظام',
            'action' => $row['action_label'],
            'subject' => $row['subject'] ? $row['subject']['type_label'].' '.($row['subject']['reference'] ?? '#'.$row['subject']['id']) : '—',
            'cycle' => $row['cycle'] ? "{$row['cycle']['name']} — {$row['cycle']['year']}" : '—',
            'details' => implode('، ', array_map(fn (array $detail): string => "{$detail['label']}: {$detail['value']}", $row['details'])) ?: '—',
            'request' => $row['request_id'] ?? '—',
        ], $trail->export($filters));

        $cycle = $filters['cycle'] ?? 'all';
        $cycleRow = ctype_digit((string) $cycle) ? collect($this->cycles->context()['cycles'])->firstWhere('id', (int) $cycle) : null;
        $scope = [['الدورة', match (true) {
            $cycle === 'none' => 'أحداث بلا دورة',
            $cycleRow !== null => "{$cycleRow['name']} — {$cycleRow['year']} (الأحداث المسجّلة بهذه الدورة فقط)",
            default => 'كل الدورات، مع الأحداث العامة',
        }], ...$this->described($filters, [
            'from' => 'من تاريخ', 'to' => 'إلى تاريخ', 'actor' => 'المنفّذ',
            'action' => ['الحركة', AuditTrail::ACTIONS], 'subject_type' => ['نوع السجل', AuditTrail::SUBJECTS], 'subject_id' => 'رقم السجل', 'request_id' => 'معرّف الطلب',
        ]), ['الترتيب', 'الأحدث أولاً']];

        return [new TableReport('audit-log', 'سجل الحركات', [
            ['key' => 'time', 'label' => 'الوقت (دمشق)', 'width' => 22], ['key' => 'actor', 'label' => 'المنفّذ', 'width' => 30],
            ['key' => 'action', 'label' => 'الحركة', 'width' => 28], ['key' => 'subject', 'label' => 'السجل المتأثر', 'width' => 24],
            ['key' => 'cycle', 'label' => 'الدورة', 'width' => 20], ['key' => 'details', 'label' => 'التفاصيل', 'width' => 40],
            ['key' => 'request', 'label' => 'معرّف الطلب', 'width' => 38],
        ], $rows, $scope, now()), $cycleRow['id'] ?? null];
    }

    /**
     * @return array{0: TableReport, 1: int|null}|JsonResponse
     */
    private function systemLogs(Request $request, int $limit): array|JsonResponse
    {
        $filters = $request->validate(SystemLogs::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات السجل.']);
        $logs = app(SystemLogs::class);
        $source = (string) ($filters['source'] ?? ($logs->sources()['sources'][0]['id'] ?? ''));
        unset($filters['cursor']);

        $entries = [];
        $cursor = null;
        $scanned = 0;
        $complete = false;
        while ($scanned < SystemLogs::EXPORT_SCAN_BYTES && count($entries) <= $limit) {
            $page = $logs->page($source, $filters + ($cursor === null ? [] : ['cursor' => $cursor]), $limit + 1 - count($entries), SystemLogs::EXPORT_SCAN_BYTES - $scanned);
            if ($page === null) {
                return response()->json(['code' => 'log_unavailable', 'message' => 'مصدر السجل غير متاح.'], 404);
            }
            $entries = [...$entries, ...$page['entries']];
            $scanned += $page['window']['scanned_bytes'];
            $cursor = $page['next_cursor'];
            if ($cursor === null) {
                $complete = true;
                break;
            }
        }
        if (count($entries) > $limit) {
            return $this->tooLarge(count($entries), $limit, (string) $request->query('format'), true);
        }
        if (! $complete) {
            return response()->json(['code' => 'export_too_large', 'message' => 'نطاق السجل أكبر مما يُقرأ في تصدير واحد. اختر مدة أقصر ثم أعد التصدير.'], 422);
        }

        $rows = array_map(fn (array $entry): array => [
            'time' => Formats::dateTime($entry['time']),
            'level' => $entry['level'],
            'category' => $entry['category'],
            'summary' => $entry['summary'],
            'location' => $entry['location'] ?? '—',
            'request' => $entry['request_id'] ?? '—',
        ], $entries);

        return [new TableReport('system-logs', 'الأخطاء التقنية', [
            ['key' => 'time', 'label' => 'الوقت (دمشق)', 'width' => 22], ['key' => 'level', 'label' => 'المستوى', 'width' => 12],
            ['key' => 'category', 'label' => 'الفئة', 'width' => 40], ['key' => 'summary', 'label' => 'الملخص', 'width' => 40],
            ['key' => 'location', 'label' => 'الموضع', 'width' => 26], ['key' => 'request', 'label' => 'معرّف الطلب', 'width' => 38],
        ], $rows, [
            ['المصدر', $source],
            ['النافذة المقروءة', 'الملف كله ضمن المرشّحات (من أحدث إدخال إلى أقدمه)'],
            ...$this->described($filters, ['level' => 'المستوى', 'from' => 'من تاريخ', 'to' => 'إلى تاريخ']),
            ['ملاحظة', 'ملخصات منقّحة فقط: لا تُعرض رسائل الأخطاء ولا تتبّع الشيفرة ولا بيانات الطلبات. هذه الإدخالات عامة لا تخص دورة.'],
        ], now()), null];
    }

    private function tooLarge(int $total, int $limit, string $format, bool $atLeast = false): JsonResponse
    {
        $count = $atLeast ? "أكثر من {$limit}" : (string) $total;

        return response()->json([
            'code' => 'export_too_large',
            'message' => "يطابق هذا النطاق {$count} صفاً، والحد الأعلى لتصدير ".($format === 'pdf' ? 'PDF' : 'Excel')." هو {$limit} صف. ضيّق المرشّحات ثم أعد التصدير؛ لم يُنشأ ملف ناقص.",
            'total' => $atLeast ? null : $total,
            'limit' => $limit,
        ], 422);
    }

    /**
     * Readable [label, value] pairs for the filters actually set.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, string|array{0: string, 1: array<string, string>}>  $labels
     * @return list<array{0: string, 1: string}>
     */
    private function described(array $filters, array $labels): array
    {
        $scope = [];
        foreach ($labels as $key => $label) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            [$name, $values] = is_array($label) ? $label : [$label, []];
            $scope[] = [$name, (string) ($values[$value] ?? $value)];
        }

        return $scope;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $sorts
     * @return array{0: string, 1: string}
     */
    private function sortDescription(array $filters, array $sorts, string $default, string $defaultDirection): array
    {
        $sort = $filters['sort'] ?? $default;
        $direction = ($filters['direction'] ?? $defaultDirection) === 'asc' ? 'تصاعدي' : 'تنازلي';

        return ['الترتيب', ($sorts[$sort] ?? $sort)." ({$direction})"];
    }

    private function pdf(string $bytes, string $code): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Formats::filename($code, 'pdf').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Writes the workbook to a private temporary file and returns its path;
     * a failed write leaves no file behind.
     */
    private function writeXlsx(TableReport $report): string
    {
        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        // Leftovers of an interrupted request are removed after an hour.
        foreach (File::files($directory) as $old) {
            if ($old->getMTime() < time() - 3600) {
                File::delete($old->getPathname());
            }
        }

        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';
        try {
            app(XlsxWriter::class)->write($report, $path);
        } catch (\Throwable $exception) {
            File::delete($path);

            throw $exception;
        }

        return $path;
    }
}
