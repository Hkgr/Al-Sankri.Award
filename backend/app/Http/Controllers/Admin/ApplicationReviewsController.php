<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ApplicationReviews;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The administrative review of a pending submitted application
 * (applications.view_submitted and applications.review, the displayed
 * account's context, CSRF and admin-writes rate limiting): «إرسال إلى
 * اللجنة» or «استبعاد وأرشفة». Repeating a decision returns it unchanged;
 * the opposite decision is refused.
 */
class ApplicationReviewsController extends Controller
{
    public function __construct(private readonly ApplicationReviews $reviews) {}

    public function refer(Request $request, int $application): JsonResponse
    {
        $validated = $request->validate(
            ['committee_id' => ['required', 'integer', 'min:1']],
            ['committee_id.*' => 'أعد تحميل الصفحة ثم أكّد الإرسال إلى اللجنة المعروضة.'],
        );

        return $this->answer($this->reviews->refer($request->user(), $application, (int) $validated['committee_id']), $application);
    }

    public function exclude(Request $request, int $application): JsonResponse
    {
        $request->merge(['reason' => is_string($request->input('reason')) ? Str::squish($request->input('reason')) : $request->input('reason')]);
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:'.ApplicationReviews::REASON_MAX, 'not_regex:/[\x00-\x1F\x7F]/']],
            ['reason.*' => 'اكتب سبب الاستبعاد باختصار (حتى '.ApplicationReviews::REASON_MAX.' حرف).'],
        );

        return $this->answer($this->reviews->exclude($request->user(), $application, $validated['reason']), $application);
    }

    private function answer(string $outcome, int $application): JsonResponse
    {
        $state = fn (): array => $this->reviews->state($application);

        return match ($outcome) {
            ApplicationReviews::REFERRED => response()->json(['code' => $outcome, 'message' => 'أُرسل ملف الترشح إلى لجنة التحكيم.', 'data' => $state()]),
            ApplicationReviews::EXCLUDED => response()->json(['code' => $outcome, 'message' => 'استُبعد ملف الترشح وأُرشف.', 'data' => $state()]),
            ApplicationReviews::UNCHANGED => response()->json(['code' => $outcome, 'message' => 'سُجّل هذا القرار من قبل، ولم يتغيّر شيء.', 'data' => $state()]),
            ApplicationReviews::ALREADY_DECIDED => response()->json([
                'code' => $outcome,
                'message' => 'صُنّف هذا الملف من قبل بقرار مختلف، فلم يُنفَّذ هذا القرار. أعد تحميل الصفحة لرؤية التصنيف الحالي.',
                'data' => $state(),
            ], 409),
            ApplicationReviews::COMMITTEE_MISSING => response()->json([
                'code' => $outcome,
                'message' => 'لا توجد لجنة تحكيم لهذا المجال الرئيسي في دورة الملف. أنشئ اللجنة واعتمدها في «لجان الحكام» أولاً؛ بقي الملف «لم يُصنّف بعد».',
            ], 409),
            ApplicationReviews::COMMITTEE_NOT_APPROVED => response()->json([
                'code' => $outcome,
                'message' => 'لجنة تحكيم هذا المجال لم تُعتمد بعد. اعتمدها بثلاثة حكام أو خمسة في «لجان الحكام» أولاً؛ بقي الملف «لم يُصنّف بعد».',
            ], 409),
            ApplicationReviews::COMMITTEE_CHANGED => response()->json([
                'code' => $outcome,
                'message' => 'تغيّرت لجنة التحكيم منذ فتحت الصفحة، ولم يُرسل الملف. أعد تحميل الصفحة وتحقق من اللجنة قبل التأكيد.',
            ], 409),
            default => response()->json(['code' => 'not_found', 'message' => 'لم يُعثر على ملف ترشح مرسل بهذا الرقم.'], 404),
        };
    }
}
