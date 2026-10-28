<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Sign in with email and password.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $request->validateCredentials();

        // Only someone who knows the password learns that the account is disabled.
        if (! $user->isActive()) {
            AuditLog::record('auth.login_refused', null, 'user', (int) $user->getKey(), ['method' => 'password', 'outcome' => 'account_disabled']);

            return response()->json(['message' => EnsureAccountIsActive::MESSAGE, 'code' => 'account_disabled'], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record('auth.login', (int) $user->getKey(), 'user', (int) $user->getKey(), ['method' => 'password']);

        return response()->json([
            'data' => new UserResource($user),
            'redirect' => SafeRedirect::afterLogin($request, $request->input('redirect')),
        ]);
    }

    /**
     * Sign out: end the session and issue a fresh CSRF token.
     */
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        if ($user !== null) {
            AuditLog::record('auth.logout', (int) $user->getKey(), 'user', (int) $user->getKey());
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
