<?php

namespace App\Http\Middleware;

use App\Candidates\NominationDetails;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before every Stage 3 and submission request (reads of files
 * included): Stage 1 must be confirmed (409 basic_information_required),
 * and the request's journey_key must name this account's confirmed Stage 2
 * journey (409 journey_changed), whose application is then the only one the
 * request may touch. Whether that application may still change is decided
 * inside each write's transaction (Documentation::lockForChange).
 *
 * The account itself was already checked by candidate.context.
 */
class EnsureDocumentationJourney
{
    public function __construct(private readonly NominationDetails $nominations) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $this->nominations->profileOf($request->user());

        if ($profile === null) {
            return response()->json(['code' => NominationDetails::BASIC_INFORMATION_REQUIRED, 'message' => 'أكمل البيانات الأساسية (المرحلة 1) وأكّد حفظها أولاً.'], 409);
        }

        $key = $request->isMethod('GET') ? $request->query('journey_key') : $request->input('journey_key');
        $journey = $this->nominations->confirmedByKey($profile, $key);

        if ($journey === null) {
            return response()->json(['code' => NominationDetails::JOURNEY_CHANGED, 'message' => 'تغيّرت بيانات هذه الصفحة منذ فتحتها، ولم يُحفظ شيء. أعد تحميل الصفحة.'], 409);
        }

        $request->attributes->set('candidate_journey', $journey);

        return $next($request);
    }
}
