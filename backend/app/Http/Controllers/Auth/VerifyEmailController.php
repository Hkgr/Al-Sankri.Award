<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Confirm the signed-in account's email from an emailed link, then show
     * the result on the /verify-email page.
     *
     * The link is signed relative to the site, so a proxy that changes the
     * scheme or host cannot invalidate it. Signed-out visitors are sent to
     * the login page first and return here afterwards.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidRelativeSignature()) {
            return redirect('/verify-email?status=invalid');
        }

        $user = $request->user();

        if (! hash_equals((string) $user->getKey(), $id) || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect('/verify-email?status=mismatch');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect('/verify-email?status=already');
        }

        $user->markEmailAsVerified();
        AuditLog::record('auth.email_verified', (int) $user->getKey(), 'user', (int) $user->getKey());
        event(new Verified($user));

        return redirect('/verify-email?status=verified');
    }
}
