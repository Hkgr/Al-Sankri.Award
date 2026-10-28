<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request one server-generated identifier (never taken from
 * the client). It is added to the log context, so each application log
 * entry of the request carries it, recorded on audit rows (audit_logs.
 * request_id), and returned as X-Request-Id so a reported problem can be
 * matched to «السجل».
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
