<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administration endpoints (`admin` or `admin:<permission>`): the signed-in
 * account must hold that permission (or, without one, any administration
 * permission) through a current role grant. Candidates and judges are
 * refused, whatever else the request claims.
 */
class EnsureAdministrationPermission
{
    public const MESSAGE = 'هذه الصفحة مخصّصة لإدارة الجائزة، وحسابك لا يملك صلاحية الوصول إليها.';

    public function __construct(private readonly AdminAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        $allowed = $user !== null && ($permission === null ? $this->access->isAdmin($user) : $this->access->can($user, $permission));

        if (! $allowed) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'not_admin'], 403);
        }

        return $next($request);
    }
}
