<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\BasicProfiles;
use App\Candidates\Documentation;
use App\Candidates\NominationDetails;
use App\Candidates\Submissions;
use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateOverviewController extends Controller
{
    /**
     * The dashboard's stage states: statuses and dates only, never personal
     * details, places or funding.
     */
    public function __invoke(Request $request, BasicProfiles $profiles, NominationDetails $nominations, Documentation $documentation, Submissions $submissions): JsonResponse
    {
        $journey = $documentation->journeyOf($request->user());

        return response()->json([
            'data' => [
                'basic_information' => $profiles->stage($request->user()),
                'nomination_details' => $nominations->stage($request->user()),
                'documentation' => $documentation->stage($request->user()),
                // The receipt only: reference number, time, version and processing status.
                'submission' => $journey === null ? null : $submissions->receipt(Application::query()->findOrFail($journey->application_id)),
            ],
        ]);
    }
}
