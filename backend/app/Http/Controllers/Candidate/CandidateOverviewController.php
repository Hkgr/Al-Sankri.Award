<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\BasicProfiles;
use App\Candidates\NominationDetails;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateOverviewController extends Controller
{
    /**
     * The dashboard's stage states: statuses and dates only, never personal
     * details, places or funding.
     */
    public function __invoke(Request $request, BasicProfiles $profiles, NominationDetails $nominations): JsonResponse
    {
        return response()->json([
            'data' => [
                'basic_information' => $profiles->stage($request->user()),
                'nomination_details' => $nominations->stage($request->user()),
            ],
        ]);
    }
}
