<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleAccounts;
use App\Auth\GoogleAuthConfig;
use App\Auth\GoogleIdentity;
use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Google sign-in with Socialite's server-side authorization-code flow.
 *
 * The OAuth "state" and the PKCE verifier live in the session and are
 * checked on return; the flow is never stateless. Only the openid, email
 * and profile scopes are requested, without offline access, and Google's
 * tokens are used once to read the identity and then discarded.
 *
 * Outcomes reach the interface as a short code in the query string
 * (/login?google=… or /account?google=…), never as provider details.
 */
class GoogleAuthController extends Controller
{
    public const LINK_SESSION_KEY = 'google_link';

    public const LINK_LIFETIME_MINUTES = 10;

    private const INTENT_SESSION_KEY = 'google_intent';

    private const REDIRECT_SESSION_KEY = 'google_redirect';

    public function __construct(
        private readonly GoogleAuthConfig $config,
        private readonly GoogleAccounts $accounts,
    ) {}

    /**
     * Send the visitor to Google: to sign in (the default), or to link
     * Google to their account after confirming their password (intent=link).
     */
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $linking = $request->query('intent') === 'link';

        if (! $this->config->available()) {
            return redirect($linking ? '/account?google=unavailable' : '/login?google=unavailable');
        }

        if ($linking) {
            if (! $this->hasPendingLink($request)) {
                return redirect('/account?google=link_expired');
            }
        } elseif ($request->user() !== null) {
            return redirect(SafeRedirect::DEFAULT);
        } else {
            $request->session()->put(self::REDIRECT_SESSION_KEY, SafeRedirect::path($request->query('redirect')));
        }

        $request->session()->put(self::INTENT_SESSION_KEY, $linking ? 'link' : 'login');

        return $this->driver()->redirect();
    }

    /**
     * Google returns here with an authorization code, or with an error.
     */
    public function callback(Request $request): RedirectResponse
    {
        $linking = $request->session()->pull(self::INTENT_SESSION_KEY) === 'link';
        $failure = $linking ? '/account?google=' : '/login?google=';

        if (! $this->config->available()) {
            return redirect($failure.'unavailable');
        }

        if ($request->filled('error')) {
            $request->session()->forget(['state', 'code_verifier']);

            return redirect($failure.($request->query('error') === 'access_denied' ? 'cancelled' : 'failed'));
        }

        try {
            $identity = GoogleIdentity::fromSocialite($this->driver()->user());
        } catch (InvalidStateException) {
            return redirect($failure.'invalid_state');
        } catch (Throwable $exception) {
            // Class and HTTP status only: never the code, tokens or response body.
            Log::warning('Google sign-in failed.', [
                'exception' => $exception::class,
                'status' => $exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null,
            ]);

            return redirect($failure.'failed');
        }

        return $linking ? $this->completeLink($request, $identity) : $this->completeSignIn($request, $identity);
    }

    private function completeSignIn(Request $request, GoogleIdentity $identity): RedirectResponse
    {
        [$outcome, $user] = $this->accounts->signIn($identity);

        if ($user === null) {
            return redirect('/login?google='.$outcome);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        if ($outcome === GoogleAccounts::CREATED && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect(SafeRedirect::afterLogin($request, $request->session()->pull(self::REDIRECT_SESSION_KEY)));
    }

    private function completeLink(Request $request, GoogleIdentity $identity): RedirectResponse
    {
        if (! $this->hasPendingLink($request)) {
            return redirect('/account?google=link_expired');
        }

        $request->session()->forget(self::LINK_SESSION_KEY);

        return redirect('/account?google='.$this->accounts->link($request->user(), $identity));
    }

    /**
     * Linking was requested, with the password confirmed, by the account
     * that is signed in now, and only a few minutes ago.
     */
    private function hasPendingLink(Request $request): bool
    {
        $pending = $request->session()->get(self::LINK_SESSION_KEY);
        $user = $request->user();

        return is_array($pending)
            && $user !== null
            && (int) ($pending['user_id'] ?? 0) === (int) $user->getKey()
            && (int) ($pending['expires_at'] ?? 0) >= now()->getTimestamp();
    }

    private function driver(): GoogleProvider
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver
            ->setScopes(['openid', 'email', 'profile'])
            ->enablePKCE()
            ->with(['prompt' => 'select_account']);
    }
}
