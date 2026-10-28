<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use App\Http\Controllers\SpaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /admin pages themselves (routes/web.php). A signed-out visitor is sent
 * to sign in and brought back; a signed-in account without administration
 * access gets the app's page with status 403, which shows the refusal. The
 * page holds no data either way: every admin API checks again.
 */
class EnsureAdministrationPage
{
    public function __construct(private readonly AdminAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect('/login?redirect='.rawurlencode($request->getRequestUri()));
        }

        if (! $user->isActive() || ! $this->access->isAdmin($user)) {
            return SpaController::withStatus(403);
        }

        return $next($request);
    }
}
