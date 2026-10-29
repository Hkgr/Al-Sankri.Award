<?php

namespace App\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «سجل الحركات»: the rows of audit_logs (App\Support\AuditLog), newest
 * first, read-only. Historical rows are shown as stored; nothing is
 * rewritten or invented.
 *
 * - The cycle filter uses audit_logs.cycle_id only: an event is in a cycle
 *   when it was recorded with that cycle. Rows recorded without one
 *   (historical rows, global or account events) stay unattributed; they
 *   appear under «كل الدورات» and «بلا دورة», never under a guessed cycle.
 * - Details show only allowlisted keys with scalar values (codes, ids,
 *   counts, outcomes) from the stored payload; anything else is withheld.
 */
class AuditTrail
{
    public const PER_PAGE = [25, 50, 100];

    /** Arabic labels of the recorded actions; an unknown code is shown as itself. */
    public const ACTIONS = [
        'user.registered' => 'تسجيل حساب جديد',
        'user.created' => 'إنشاء حساب من الإدارة',
        'user_role.granted' => 'منح دور على مستوى المنصة',
        'user_role.revoked' => 'سحب دور على مستوى المنصة',
        'auth.login' => 'تسجيل الدخول',
        'auth.login_failed' => 'محاولة دخول غير ناجحة',
        'auth.login_refused' => 'رفض دخول حساب معطّل',
        'auth.login_throttled' => 'إيقاف مؤقت لمحاولات الدخول',
        'auth.logout' => 'تسجيل الخروج',
        'auth.password_reset' => 'تعيين كلمة مرور جديدة',
        'auth.email_verified' => 'تأكيد البريد الإلكتروني',
        'auth.google_linked' => 'ربط حساب Google',
        'candidate_profile.completed' => 'تأكيد البيانات الأساسية (المرحلة 1)',
        'nomination_details.confirmed' => 'تأكيد فئة الترشح وبيانات العمل (المرحلة 2)',
        'documentation.file_added' => 'رفع ملف توثيق',
        'documentation.file_removed' => 'حذف ملف توثيق',
        'documentation.links_saved' => 'حفظ روابط التوثيق',
        'documentation.confirmed' => 'تأكيد ملفات العمل وتوثيقه (المرحلة 3)',
        'application.submitted' => 'الإرسال النهائي لملف الترشح',
        'application.referred' => 'إرسال ملف الترشح إلى لجنة التحكيم',
        'application.excluded' => 'استبعاد ملف الترشح وأرشفته',
        'committee.created' => 'إنشاء لجنة تحكيم',
        'committee.approved' => 'اعتماد لجنة تحكيم',
        'committee.returned_to_draft' => 'إعادة لجنة تحكيم إلى المسودة',
        'committee_member.added' => 'إضافة حكَم إلى لجنة',
        'committee_member.ended' => 'إنهاء عضوية حكَم',
        'cycle_user_role.granted' => 'منح دور في دورة',
        'cycle_user_role.revoked' => 'سحب دور في دورة',
        'judging_assignments.provisioned' => 'توزيع ملفات على الحكام',
        'evaluation.finalized' => 'إرسال تقييم حكَم',
        'report.exported' => 'تصدير تقرير',
        'file.downloaded' => 'تنزيل مرفق',
        'account_policy.published' => 'نشر إصدار سياسة',
        'award_cycle.created' => 'إنشاء دورة',
        'award_cycle.published' => 'نشر دورة',
        'award_cycle.geography_set' => 'تحديد بلد تنفيذ الدورة',
        'award_domain.created' => 'إنشاء مجال',
        'cycle_domain.created' => 'إضافة مجال إلى دورة',
        'cycle_domain.opened' => 'فتح مجال للترشح',
        'cycle_domain_subcategory.created' => 'إضافة فئة فرعية',
        'cycle_stage.created' => 'إنشاء مرحلة',
        'cycle_stage.window_changed' => 'تغيير موعد مرحلة',
        'cycle_stage.enabled' => 'تفعيل مرحلة',
        'cycle_stage.closed' => 'إغلاق مرحلة',
        'role.provisioned' => 'تهيئة دور (ترحيل قاعدة البيانات)',
        'permission.provisioned' => 'تهيئة صلاحية (ترحيل قاعدة البيانات)',
        'role_permission.provisioned' => 'ربط صلاحية بدور (ترحيل قاعدة البيانات)',
        'review_classification.backfilled' => 'تصنيف الملفات المرسلة سابقاً (ترحيل قاعدة البيانات)',
        'nomination_fields.purged' => 'حذف بيانات الأسئلة الملغاة من فئة الترشح (ترحيل قاعدة البيانات)',
    ];

    public const SUBJECTS = [
        'user' => 'حساب',
        'application' => 'ملف ترشح',
        'candidate_profile' => 'بيانات أساسية',
        'committee' => 'لجنة',
        'evaluation' => 'تقييم',
        'award_cycle' => 'دورة',
        'award_domain' => 'مجال',
        'cycle_domain' => 'مجال في دورة',
        'cycle_domain_subcategory' => 'فئة فرعية',
        'cycle_stage' => 'مرحلة',
        'account_policy_version' => 'إصدار سياسة',
        'role' => 'دور',
        'permission' => 'صلاحية',
        'role_permission' => 'ربط صلاحية بدور',
    ];

    /** Payload keys shown in the details (scalar values only). */
    public const DETAIL_KEYS = [
        'role' => 'الدور', 'source' => 'المصدر', 'method' => 'الطريقة', 'outcome' => 'النتيجة', 'cycle_id' => 'الدورة',
        'domain' => 'المجال', 'subcategory' => 'الفئة', 'code' => 'الرمز', 'type' => 'النوع', 'profile_type' => 'نوع المتقدم',
        'purpose' => 'الغرض', 'kind' => 'نوع الملف', 'size_bytes' => 'الحجم بالبايت', 'count' => 'العدد', 'member_count' => 'عدد الأعضاء',
        'revision' => 'رقم المراجعة', 'documentation_revision' => 'رقم مراجعة التوثيق', 'version_number' => 'رقم النسخة', 'replaced' => 'استبدال',
        'created' => 'حساب جديد', 'reference_number' => 'الرقم المرجعي', 'report' => 'التقرير', 'format' => 'الصيغة', 'rows' => 'عدد الصفوف',
        'user_id' => 'الحساب', 'application_id' => 'ملف الترشح', 'application_version_id' => 'النسخة المرسلة', 'applicant_id' => 'المتقدم',
        'candidate_nomination_id' => 'رحلة الترشح', 'committee_member_id' => 'العضوية', 'member_id' => 'العضوية', 'file_link_id' => 'المرفق',
        'judging_assignment_id' => 'إسناد التحكيم', 'rubric_id' => 'معايير التقييم', 'assignments' => 'عدد الإسنادات',
        'role_id' => 'الدور', 'permission_id' => 'الصلاحية', 'migration' => 'الترحيل',
        'committee_id' => 'اللجنة', 'pending' => 'لم يُصنّف بعد', 'legacy_referred' => 'إحالة سابقة من سجل التحكيم',
        'in_judging_without_assignments' => 'قيد التحكيم دون إسنادات', 'assignments_in_several_committees' => 'إسنادات في أكثر من لجنة',
        'applications' => 'ملفات الترشح', 'locations' => 'أماكن التنفيذ المحذوفة', 'partnerships' => 'الشراكات المحذوفة',
        'versions' => 'النسخ المرسلة المعدّلة', 'form_snapshots' => 'لقطات النموذج المعدّلة', 'audit_rows' => 'سجلات الحركات المنقّحة',
        'unverified_old_hashes' => 'بصمات سابقة لم تتطابق',
    ];

    /**
     * The view's filters (shared by the table and its exports).
     *
     * @return array<string, list<mixed>>
     */
    public static function filterRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'actor' => ['nullable', 'string', 'max:150'],
            'action' => ['nullable', 'string', 'max:100', 'regex:/^[a-z_.]+$/'],
            'subject_type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z_]+$/'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'cycle' => ['nullable', 'regex:/^(all|none|[1-9][0-9]*)$/'],
            'request_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9a-f-]{8,64}$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(array $filters): array
    {
        $total = $this->count($filters);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);

        return ['data' => $this->rows($filters, $page, $perPage), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage]];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * Every matching row (exports); the caller checks count() first.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters): array
    {
        return $this->rows($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters, ?int $page = null, ?int $perPage = null): array
    {
        $rows = $this->filtered($filters)
            ->leftJoin('users as actors', 'actors.id', '=', 'audit_logs.actor_user_id')
            ->leftJoin('award_cycles as cycles', 'cycles.id', '=', 'audit_logs.cycle_id')
            ->orderByDesc('audit_logs.occurred_at')->orderByDesc('audit_logs.id')
            ->when($page !== null, fn (Builder $q) => $q->forPage((int) $page, (int) $perPage))
            ->get([
                'audit_logs.id', 'audit_logs.occurred_at', 'audit_logs.action', 'audit_logs.subject_type', 'audit_logs.subject_id',
                'audit_logs.after_data', 'audit_logs.before_data', 'audit_logs.request_id', 'audit_logs.actor_user_id', 'audit_logs.cycle_id',
                'actors.name as actor_name', 'actors.email as actor_email', 'cycles.name_ar as cycle_name', 'cycles.year as cycle_year',
            ]);

        $references = $this->references($rows);

        return $rows->map(function (object $row) use ($references): array {
            $label = self::ACTIONS[$row->action] ?? $row->action;
            $subjectLabel = $row->subject_type === null ? null : (self::SUBJECTS[$row->subject_type] ?? $row->subject_type);
            $reference = $row->subject_type === null ? null : ($references["{$row->subject_type}:{$row->subject_id}"] ?? null);

            return [
                'id' => (int) $row->id,
                'occurred_at' => Carbon::parse($row->occurred_at, 'UTC')->toIso8601ZuluString(),
                'actor' => $row->actor_user_id === null ? null : ['id' => (int) $row->actor_user_id, 'name' => $row->actor_name, 'email' => $row->actor_email],
                'action' => $row->action,
                'action_label' => $label,
                'subject' => $row->subject_type === null ? null : [
                    'type' => $row->subject_type, 'type_label' => $subjectLabel, 'id' => (int) $row->subject_id, 'reference' => $reference,
                ],
                'cycle' => $row->cycle_id === null ? null : ['id' => (int) $row->cycle_id, 'name' => $row->cycle_name, 'year' => (int) $row->cycle_year],
                'request_id' => $row->request_id,
                'summary' => trim($label.($subjectLabel ? " · {$subjectLabel}".($reference ? " {$reference}" : " #{$row->subject_id}") : '')),
                'details' => [...$this->details($row->before_data, 'قبل: '), ...$this->details($row->after_data, '')],
            ];
        })->all();
    }

    /**
     * Safe references of the affected records, in bulk: an application's
     * reference number, a committee's name, an account's name.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, string>
     */
    private function references($rows): array
    {
        $ids = fn (string $type): array => $rows->where('subject_type', $type)->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $references = [];
        foreach ([['application', 'applications', 'reference_number'], ['committee', 'committees', 'name_ar'], ['user', 'users', 'name']] as [$type, $table, $column]) {
            $wanted = $ids($type);
            if ($wanted !== []) {
                foreach (DB::table($table)->whereIn('id', $wanted)->whereNotNull($column)->pluck($column, 'id') as $id => $value) {
                    $references["{$type}:{$id}"] = (string) $value;
                }
            }
        }

        return $references;
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function details(mixed $json, string $prefix): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            return [];
        }

        $shown = [];
        $withheld = 0;
        foreach ($data as $key => $value) {
            if (isset(self::DETAIL_KEYS[$key]) && (is_scalar($value) || $value === null)) {
                $text = is_bool($value) ? ($value ? 'نعم' : 'لا') : (string) ($value ?? '—');
                $shown[] = ['key' => (string) $key, 'label' => $prefix.self::DETAIL_KEYS[$key], 'value' => mb_substr($text, 0, 100)];
            } else {
                $withheld++;
            }
        }
        if ($withheld > 0) {
            $shown[] = ['key' => '_withheld', 'label' => $prefix.'حقول أخرى', 'value' => "{$withheld} حقل غير معروض"];
        }

        return $shown;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = DB::table('audit_logs');

        if (! empty($filters['from'])) {
            $query->where('audit_logs.occurred_at', '>=', Carbon::parse($filters['from'], 'Asia/Damascus')->startOfDay()->utc()->format('Y-m-d H:i:s.u'));
        }
        if (! empty($filters['to'])) {
            $query->where('audit_logs.occurred_at', '<', Carbon::parse($filters['to'], 'Asia/Damascus')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.u'));
        }
        if (isset($filters['actor']) && trim((string) $filters['actor']) !== '') {
            $actor = trim((string) $filters['actor']);
            if ($actor === 'system') {
                $query->whereNull('audit_logs.actor_user_id');
            } else {
                $like = '%'.addcslashes($actor, '\\%_').'%';
                $query->whereIn('audit_logs.actor_user_id', DB::table('users')->select('id')->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            }
        }
        if (! empty($filters['action'])) {
            $query->where('audit_logs.action', $filters['action']);
        }
        if (! empty($filters['subject_type'])) {
            $query->where('audit_logs.subject_type', $filters['subject_type']);
            if (! empty($filters['subject_id'])) {
                $query->where('audit_logs.subject_id', (int) $filters['subject_id']);
            }
        }
        $cycle = $filters['cycle'] ?? 'all';
        if ($cycle === 'none') {
            $query->whereNull('audit_logs.cycle_id');
        } elseif ($cycle !== 'all' && $cycle !== null) {
            $query->where('audit_logs.cycle_id', (int) $cycle);
        }
        if (! empty($filters['request_id'])) {
            $query->where('audit_logs.request_id', $filters['request_id']);
        }

        return $query;
    }
}
