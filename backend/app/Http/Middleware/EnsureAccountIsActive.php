<?php

namespace App\Http\Middleware;

use App\Auth\RoleExclusivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that has been disabled since it signed in.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'هذا الحساب موقوف. لا يمكن استخدامه لتسجيل الدخول.';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE, 'code' => 'account_disabled'], 403);
            }

            return redirect('/login?account=disabled');
        }

        if ($user !== null) {
            app(RoleExclusivity::class)->reconcile($user);
        }

        return $next($request);
    }
}
