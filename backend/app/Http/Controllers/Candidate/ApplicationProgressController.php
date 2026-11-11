<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\ApplicationProgress;
use App\Candidates\Documentation;
use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationProgressController extends Controller
{
    public function __invoke(Request $request, Documentation $documentation, ApplicationProgress $progress): JsonResponse
    {
        $journey = $request->attributes->get('candidate_journey') ?? $documentation->journeyOf($request->user());
        if ($journey === null) {
            return response()->json(['data' => null]);
        }
        $application = Application::query()->findOrFail($journey->application_id);
        $state = $progress->forApplication($application);
        if ($request->filled('version') && ($state === null || (string) $state['version_number'] !== $request->query('version'))) {
            return response()->json(['code' => 'progress_version_changed', 'message' => 'نسخة الملف المشار إليها غير متاحة للمتابعة. افتح ملف المشاركة من حسابك.'], 409);
        }

        return response()->json(['data' => $state, 'journey_key' => $documentation->journeyKey($journey)]);
    }
}
