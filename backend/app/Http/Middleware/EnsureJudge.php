<?php

namespace App\Http\Middleware;

use App\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Judge portal endpoints: the signed-in account holds a current `judge`
 * grant (App\Admin\AdminAccess). The grant is given only by appointing the
 * account in «لجان الحكام»; the role itself carries no permission.
 */
class EnsureJudge
{
    public const MESSAGE = 'هذه الصفحة مخصّصة لحكّام الجائزة، وحسابك ليس حكماً في دورة حالية.';

    public function __construct(private readonly AdminAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->isActive() || ! $this->access->isJudge((int) $user->getKey())) {
            return response()->json(['message' => self::MESSAGE, 'code' => 'not_judge'], 403);
        }

        return $next($request);
    }
}
