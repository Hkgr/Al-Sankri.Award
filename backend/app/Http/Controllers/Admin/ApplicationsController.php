<?php

namespace App\Http\Controllers\Admin;

use App\Admin\SubmittedApplications;
use App\Http\Controllers\Controller;
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

        return PrivateFileResponse::for($link->file);
    }
}
