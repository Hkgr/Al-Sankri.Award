<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use App\Auth\CandidateRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate endpoints are for accounts holding the «مترشح» classification
 * and not a current judge. A judge is refused even if a classification
 * remains; only administration changes roles. Staff or other accounts
 * without the classification are refused.
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
                'message' => 'حسابك مُعيَّن حكَماً في الجائزة، فلا يمكنه استخدام لوحة المترشح. استخدم بوابة الحكام؛ تغيير الأدوار يتم عبر إدارة الجائزة فقط.',
                'code' => 'is_judge',
            ], 403);
        }
        if ($user === null || ! $this->candidateRole->isHeldBy($user)) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'not_candidate'], 403);
        }

        return $next($request);
    }
}
