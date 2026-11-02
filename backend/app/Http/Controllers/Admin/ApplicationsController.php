<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAccess;
use App\Admin\SubmittedApplications;
use App\Http\Controllers\Controller;
use App\Judging\Assignments;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\PrivateFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «ملفات الترشح» for administrators (applications.view_submitted): the
 * table of submitted participations, one participation read-only, and its
 * files. Nothing here changes an application.
 */
class ApplicationsController extends Controller
{
    /** Correct the current category and resume referred delivery; submitted snapshots stay immutable. */
    public function correctCategory(Request $request, int $application): JsonResponse
    {
        $data = $request->validate(['subcategory' => ['required', 'string', 'max:80']]);

        DB::transaction(function () use ($request, $application, $data): void {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->first();
            abort_unless($actor?->isActive() && app(AdminAccess::class)->can($actor, AdminAccess::REVIEW_APPLICATIONS), 403);
            $found = DB::table('applications')->where('id', $application)->first(['cycle_id', 'cycle_domain_id']);
            abort_if($found === null, 404);
            // As in referral, approval and account removal: committee before application.
            DB::table('committees')->where('cycle_id', $found->cycle_id)->where('cycle_domain_id', $found->cycle_domain_id)
                ->where('type', 'judging')->orderBy('id')->lockForUpdate()->get(['id']);
            $row = DB::table('applications')->where('id', $application)->lockForUpdate()->first();
            abort_if($row === null || $row->current_version_id === null, 404);
            if ($row->taxonomy_review_required) {
                $category = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $row->cycle_domain_id)
                    ->where('is_active', true)->where('code', $data['subcategory'])->lockForUpdate()->first();
                abort_if($category === null, 422);
                DB::table('applications')->where('id', $application)->update(['subcategory_id' => $category->id, 'taxonomy_review_required' => false, 'updated_at' => now()]);
                AuditLog::record('application.category_corrected', (int) $actor->id, 'application', $application, ['subcategory_id' => (int) $category->id], (int) $row->cycle_id);
            }
            app(Assignments::class)->provisionReferral($application, (int) $actor->id);
        }, 3);

        // A waiting retry must not return its earlier consistent-read snapshot.
        return response()->json(['data' => $this->applications->show($application)]);
    }

    private const NOT_FOUND = 'لم يُعثر على ملف ترشح مرسل بهذا الرقم.';

    public function __construct(private readonly SubmittedApplications $applications) {}

    /**
     * Server-side search, filters, whitelisted sorting and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(SubmittedApplications::filterRules(), ['*' => 'قيمة غير صالحة في مرشّحات الجدول.']);

        return response()->json($this->applications->page($filters));
    }

    public function show(int $application): JsonResponse
    {
        $data = $this->applications->show($application);

        return $data === null
            ? response()->json(['code' => 'not_found', 'message' => self::NOT_FOUND], 404)
            : response()->json(['data' => $data]);
    }

    /**
     * A file of the application's submitted version only.
     */
    public function file(Request $request, int $application, int $attachment): BinaryFileResponse|JsonResponse
    {
        $link = $this->applications->file($application, $attachment);
        if ($link === null) {
            return response()->json(['code' => 'not_found', 'message' => 'لم يُعثر على هذا الملف في النسخة المرسلة من ملف الترشح.'], 404);
        }

        if (PrivateFileResponse::isFirstRead($request)) {
            AuditLog::record('file.downloaded', (int) $request->user()->getKey(), 'application', $application, [
                'file_link_id' => $attachment, 'role' => 'award_admin',
            ], (int) DB::table('applications')->where('id', $application)->value('cycle_id'));
        }

        return PrivateFileResponse::for($link->file, $request->query('download') === '1');
    }
}
