<?php

namespace App\Http\Middleware;

use App\Auth\CandidateRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidate endpoints are for accounts holding the «مترشح» classification.
 * Staff or other accounts without it are refused, whatever else they hold.
 */
class EnsureUserIsCandidate
{
    public const MESSAGE = 'هذه الصفحة مخصّصة لحسابات المترشحين.';

    public function __construct(private readonly CandidateRole $candidateRole) {}

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

        return $next($request);
    }
}
