<?php

namespace App\Http\Controllers\Judge;

use App\Http\Controllers\Controller;
use App\Judging\JudgePortal;
use App\Judging\Scores;
use App\Support\AuditLog;
use App\Support\PrivateFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The judge portal API. The signed-in account is a current judge
 * (middleware `judge`); each action still only reaches that account's own
 * assignments.
 */
class ProjectsController extends Controller
{
    private const NOT_FOUND = 'لم يُسند إليك هذا المشروع.';

    public function __construct(private readonly JudgePortal $portal) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->list($request->user())]);
    }

    public function show(Request $request, int $application): JsonResponse
    {
        $data = $this->portal->show($request->user(), $application);

        return $data === null
            ? response()->json(['code' => 'not_assigned', 'message' => self::NOT_FOUND], 404)
            : response()->json(['data' => $data]);
    }

    public function file(Request $request, int $application, int $attachment): BinaryFileResponse|JsonResponse
    {
        $link = $this->portal->file($request->user(), $application, $attachment);
        if ($link === null) {
            return response()->json(['code' => 'not_found', 'message' => 'لم يُعثر على هذا الملف في المشروع المسند إليك.'], 404);
        }

        if (PrivateFileResponse::isFirstRead($request)) {
            AuditLog::record('file.downloaded', (int) $request->user()->getKey(), 'application', $application, [
                'file_link_id' => $attachment, 'role' => 'judge',
            ], (int) DB::table('applications')->where('id', $application)->value('cycle_id'));
        }

        return PrivateFileResponse::for($link->file, $request->query('download') === '1');
    }

    public function send(Request $request, int $application): JsonResponse
    {
        $excluded = $request->boolean('excluded');
        $input = $request->validate([
            'score' => $excluded
                ? ['prohibited']
                : ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'notes' => ['required', 'string', 'max:4000'],
            'recommendations' => ['required', 'string', 'max:4000'],
            'excluded' => ['required', 'boolean'],
        ], [
            'score.required' => 'ضع العلامة من 100.',
            'score.numeric' => 'العلامة رقم من 0 إلى 100.',
            'score.min' => 'العلامة رقم من 0 إلى 100.',
            'score.max' => 'العلامة رقم من 0 إلى 100.',
            'score.decimal' => 'العلامة رقم من 0 إلى 100، بخانتين عشريتين على الأكثر.',
            'score.prohibited' => 'لا تُوضع علامة عند استبعاد المشارك.',
            'notes.required' => 'اكتب الملاحظات قبل الإرسال.',
            'notes.max' => 'الملاحظات أطول مما يُقبل.',
            'recommendations.required' => 'اكتب التوصيات قبل الإرسال.',
            'recommendations.max' => 'التوصيات أطول مما يُقبل.',
            'excluded.required' => 'حدّد إن كان المشارك يُستبعد.',
            'excluded.boolean' => 'حدّد إن كان المشارك يُستبعد.',
        ]);

        $notes = trim($input['notes']);
        $recommendations = trim($input['recommendations']);
        if ($notes === '' || $recommendations === '') {
            $errors = [];
            if ($notes === '') {
                $errors['notes'] = ['اكتب الملاحظات قبل الإرسال.'];
            }
            if ($recommendations === '') {
                $errors['recommendations'] = ['اكتب التوصيات قبل الإرسال.'];
            }

            return response()->json([
                'message' => $errors['notes'][0] ?? $errors['recommendations'][0],
                'errors' => $errors,
            ], 422);
        }

        $result = $this->portal->send(
            $request->user(),
            $application,
            $excluded ? null : Scores::normalize($input['score']),
            $notes,
            $recommendations,
            $excluded,
        );

        return match ($result) {
            JudgePortal::NOT_ASSIGNED => response()->json(['code' => 'not_assigned', 'message' => self::NOT_FOUND], 404),
            JudgePortal::ALREADY_SENT => response()->json(['code' => 'already_sent', 'message' => 'أُرسلت علامتك إلى الإدارة، ولا تُعدَّل من هنا.'], 409),
            default => response()->json(['data' => $this->portal->show($request->user(), $application)]),
        };
    }
}
