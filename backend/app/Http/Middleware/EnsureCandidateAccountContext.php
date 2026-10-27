<?php

namespace App\Http\Middleware;

use App\Candidates\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a stage 1 check or confirmation sent from a form opened for
 * another account (see App\Candidates\AccountContext). Runs before
 * validation, so nothing from the other account's form is validated,
 * stored or answered with this account's data.
 */
class EnsureCandidateAccountContext
{
    public const MESSAGE = 'فُتح هذا النموذج لحساب غير الحساب المسجّل دخوله الآن في هذا المتصفح، فلم يُرسل ولم يُحفظ شيء.';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! AccountContext::matches($request->user(), $request->input('account_context'))) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'account_mismatch'], 409);
        }

        return $next($request);
    }
}
