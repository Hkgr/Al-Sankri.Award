<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use App\Auth\CandidateRole;
use App\Auth\RoleExclusivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate endpoints are for accounts holding the «مترشح» classification
 * and no other effective platform or cycle assignment. Classification is
 * reconciled by the active-account middleware before this authorization.
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

        // A judge is told why first, whatever classification remains.
        if ($user !== null && $this->access->isJudge((int) $user->getKey())) {
            return response()->json([
                'message' => 'حسابك مكلّف بالتحكيم حالياً، فلا يمكنه استخدام لوحة المترشح. استخدم بوابة الحكام. يعود دور المترشح بعد انتهاء جميع التكليفات الأخرى.',
                'code' => 'is_judge',
            ], 403);
        }
        if ($user === null || ! $this->candidateRole->isHeldBy($user)) {
            return response()->json(['message' => $user === null ? self::MESSAGE : RoleExclusivity::MESSAGE, 'code' => $user === null ? 'not_candidate' : RoleExclusivity::UNAVAILABLE], 403);
        }

        return $next($request);
    }
}
