<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use App\Http\Controllers\SpaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /judge pages. A signed-out visitor is sent to sign in and brought
 * back; a signed-in account that is not a current judge gets the page with
 * status 403. The page holds no projects either way.
 */
class EnsureJudgePage
{
    public function __construct(private readonly AdminAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect('/login?redirect='.rawurlencode($request->getRequestUri()));
        }

        if (! $user->isActive() || ! $this->access->isJudge((int) $user->getKey())) {
            return SpaController::withStatus(403);
        }

        return $next($request);
    }
}
