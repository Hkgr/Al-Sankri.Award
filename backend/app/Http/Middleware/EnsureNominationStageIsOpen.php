<?php

namespace App\Http\Middleware;

use App\Candidates\NominationDetails;
use App\Http\Resources\NominationDetailsResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before a Stage 2 section check or confirmation is validated:
 *
 * - Stage 1 must be confirmed (409 basic_information_required);
 * - a retry of a journey that is already confirmed gets its stored result
 *   (409 nomination_details_already_confirmed, with the data), even when
 *   the stage has closed since;
 * - otherwise the stage must be usable now (409 nomination_unavailable), and
 *   the form's journey_key must be this account's key for the usable cycle
 *   (409 journey_changed), so a form opened for another cycle or a cycle
 *   configuration that changed meanwhile is refused.
 *
 * The account itself was already checked by candidate.context.
 */
class EnsureNominationStageIsOpen
{
    public function __construct(private readonly NominationDetails $details) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        [$refusal, $cycle, $existing] = $this->details->gate($request->user(), $request->input('journey_key'));

        if ($refusal !== null) {
            return response()->json(match ($refusal) {
                NominationDetails::BASIC_INFORMATION_REQUIRED => ['code' => $refusal, 'message' => 'أكمل البيانات الأساسية (المرحلة 1) وأكّد حفظها أولاً.'],
                NominationDetails::ALREADY_CONFIRMED => [
                    'code' => $refusal,
                    'message' => 'بيانات هذه المرحلة محفوظة مسبقاً ومتاحة للعرض فقط، ولم يُغيَّر فيها شيء.',
                    'data' => new NominationDetailsResource($existing),
                ],
                NominationDetails::UNAVAILABLE => ['code' => $refusal, 'message' => 'هذه المرحلة غير متاحة حالياً، ولم يُحفظ شيء. ما أدخلته ما زال في الصفحة.'],
                default => ['code' => $refusal, 'message' => 'تغيّرت إعدادات هذه المرحلة منذ فتحت النموذج، ولم يُحفظ شيء. أعد تحميل الصفحة.'],
            }, 409);
        }

        $request->attributes->set('nomination_cycle', $cycle);

        return $next($request);
    }
}
