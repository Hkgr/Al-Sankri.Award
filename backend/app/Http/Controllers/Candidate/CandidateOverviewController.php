<?php

namespace App\Http\Controllers\Candidate;

use App\Candidates\BasicProfiles;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateOverviewController extends Controller
{
    /**
     * The dashboard's stage states: statuses and dates only, never personal
     * details of the basic information.
     */
    public function __invoke(Request $request, BasicProfiles $profiles): JsonResponse
    {
        return response()->json([
            'data' => [
                'basic_information' => $profiles->stage($request->user()),
            ],
        ]);
    }
}
