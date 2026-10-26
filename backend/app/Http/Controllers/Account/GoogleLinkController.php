<?php

namespace App\Http\Controllers\Account;

use App\Auth\GoogleAccounts;
use App\Auth\GoogleAuthConfig;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleLinkController extends Controller
{
    /**
     * Start linking Google to the signed-in account. The owner confirms
     * their current password here (local authentication and explicit
     * consent); the browser then goes to Google with intent=link.
     *
     * The request is bound to the account's current credentials and status,
     * and is re-checked at Google's callback. The link itself is stored only
     * if the account's email is verified, or the Google identity is
     * authoritative for that same address (GoogleAccounts::link).
     */
    public function store(Request $request, GoogleAuthConfig $config, GoogleAccounts $accounts): JsonResponse
    {
        if (! $config->available()) {
            return response()->json(['message' => 'ربط حساب Google غير متاح حالياً.', 'code' => 'google_unavailable'], 503);
        }

        $user = $request->user();

        if ($accounts->isLinked($user)) {
            return response()->json(['message' => 'حساب Google مرتبط بهذا الحساب مسبقاً.', 'code' => 'already_linked'], 409);
        }

        if (! $user->hasPassword()) {
            return response()->json(['message' => 'عيّن كلمة مرور لحسابك أولاً من صفحة «نسيت كلمة المرور».', 'code' => 'password_required'], 409);
        }

        $request->validate(['password' => ['required', 'string', 'current_password:web']]);

        $request->session()->put(GoogleAuthController::LINK_SESSION_KEY, [
            'user_id' => $user->getKey(),
            'fingerprint' => GoogleAuthController::linkFingerprint($user),
            'expires_at' => now()->addMinutes(GoogleAuthController::LINK_LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return response()->json(['redirect' => '/auth/google/redirect?intent=link']);
    }
}
