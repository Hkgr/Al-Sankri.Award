<?php

namespace App\Http\Controllers\Admin;

use App\Admin\SubmittedApplications;
use App\Candidates\NominationTaxonomy;
use App\Http\Controllers\Controller;
use App\Support\PrivateFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $filters = $request->validate([
            'cycle' => ['nullable', 'integer', 'min:1'],
            'domain' => ['nullable', 'string', Rule::in(NominationTaxonomy::domainCodes())],
            'subcategory' => ['nullable', 'string', 'max:64'],
            'applicant_type' => ['nullable', Rule::in(array_keys(SubmittedApplications::APPLICANT_TYPES))],
            'status' => ['nullable', Rule::in(SubmittedApplications::STATUSES)],
            'q' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', Rule::in(array_keys(SubmittedApplications::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(SubmittedApplications::PER_PAGE)],
        ], ['*' => 'قيمة غير صالحة في مرشّحات الجدول.']);

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

        return $link === null
            ? response()->json(['code' => 'not_found', 'message' => 'لم يُعثر على هذا الملف في النسخة المرسلة من ملف الترشح.'], 404)
            : PrivateFileResponse::for($link->file, $request->query('download') === '1');
    }
}
