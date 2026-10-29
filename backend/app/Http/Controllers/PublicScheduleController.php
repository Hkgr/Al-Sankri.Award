<?php

namespace App\Http\Controllers;

use App\Candidates\PublicSchedule;
use Illuminate\Http\JsonResponse;

/**
 * The public schedule for the homepage countdown: the application window,
 * the judging period and the server's current time. No personal data.
 */
class PublicScheduleController extends Controller
{
    public function __invoke(PublicSchedule $schedule): JsonResponse
    {
        return response()->json(['data' => $schedule->current()]);
    }
}
