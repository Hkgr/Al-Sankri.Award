<?php

namespace App\Http\Controllers\Admin;

use App\Admin\CycleSchedule;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «المواعيد»: the registration and judging windows of one award cycle.
 */
class ScheduleController extends Controller
{
    public function __construct(private readonly CycleSchedule $schedule) {}

    public function show(int $cycle): JsonResponse
    {
        $data = $this->schedule->forCycle($cycle);

        return $data === null
            ? response()->json(['message' => 'لم يُعثر على هذه الدورة.', 'code' => 'cycle_missing'], 404)
            : response()->json(['data' => $data]);
    }

    public function update(Request $request, int $cycle): JsonResponse
    {
        $input = $request->validate([
            'nomination_opens_on' => ['required', 'date_format:Y-m-d'],
            'nomination_closes_on' => ['required', 'date_format:Y-m-d'],
            'judging_opens_on' => ['required', 'date_format:Y-m-d'],
            'judging_closes_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'nomination_opens_on.required' => 'حدّد تاريخ بدء الترشح.',
            'nomination_opens_on.date_format' => 'تاريخ بدء الترشح غير صالح.',
            'nomination_closes_on.required' => 'حدّد تاريخ انتهاء التسجيل.',
            'nomination_closes_on.date_format' => 'تاريخ انتهاء التسجيل غير صالح.',
            'judging_opens_on.required' => 'حدّد تاريخ بدء التحكيم.',
            'judging_opens_on.date_format' => 'تاريخ بدء التحكيم غير صالح.',
            'judging_closes_on.required' => 'حدّد تاريخ انتهاء التحكيم.',
            'judging_closes_on.date_format' => 'تاريخ انتهاء التحكيم غير صالح.',
        ]);

        [$result, $data] = $this->schedule->update($request->user(), $cycle, $input);

        return match ($result) {
            CycleSchedule::CYCLE_MISSING => response()->json(['message' => 'لم يُعثر على هذه الدورة.', 'code' => $result], 404),
            CycleSchedule::INVALID_WINDOW => response()->json([
                'message' => 'كل فترة تحتاج تاريخ بداية قبل تاريخ نهايتها.',
                'code' => $result,
            ], 422),
            CycleSchedule::JUDGING_BEFORE_NOMINATION => response()->json([
                'message' => 'فترة التحكيم تبدأ بعد انتهاء التسجيل، ولا تسبقه.',
                'code' => $result,
            ], 422),
            default => response()->json(['data' => $data]),
        };
    }
}
