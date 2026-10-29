<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use App\Auth\CandidateRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate endpoints are for accounts holding the «مترشح» classification
 * and not a current judge. A judge registers as a participant first, which
 * cancels the judge role. Staff or other accounts without the
 * classification are refused.
 */
class EnsureUserIsCandidate
{
    public const MESSAGE = 'هذه الصفحة مخصّصة لحسابات المترشحين.';

    public function __construct(private readonly CandidateRole $candidateRole, private readonly AdminAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->candidateRole->isHeldBy($user)) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'not_candidate'], 403);
        }
        if ($this->access->isJudge((int) $user->getKey())) {
            return response()->json([
                'message' => 'حسابك حكم حالياً. التسجيل كمشارك يلغي صفة الحكم قبل فتح لوحة المترشح.',
                'code' => 'is_judge',
            ], 403);
        }

        return $next($request);
    }
}
