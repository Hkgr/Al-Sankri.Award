<?php

namespace App\Http\Middleware;

use App\Candidates\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a candidate read or write made by a page that is showing another
 * account than the one now signed in (see App\Candidates\AccountContext).
 *
 * The page sends the context of the account it displays: the
 * X-Candidate-Context header on reads, the account_context field on writes.
 * The check runs before validation and before any stored data is read, so
 * nothing of the signed-in account is answered to a page showing another
 * account, and nothing from that page is validated or stored. The session
 * remains the only source of the account.
 */
class EnsureCandidateAccountContext
{
    public const HEADER = 'X-Candidate-Context';

    public const MESSAGE = 'الحساب المسجّل دخوله الآن في هذا المتصفح غير الحساب المعروض في هذه الصفحة، فلم يُرسل ولم يُعرض ولم يُحفظ شيء.';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $source = null): Response
    {
        // `candidate.context:query` (file downloads and video playback, where
        // the browser cannot add a header): the value comes in the query.
        $context = $source === 'query'
            ? $request->query('account_context')
            : ($request->isMethod('GET') ? $request->header(self::HEADER) : $request->input('account_context'));

        if (! AccountContext::matches($request->user(), $context)) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'account_mismatch'], 409);
        }

        return $next($request);
    }
}
