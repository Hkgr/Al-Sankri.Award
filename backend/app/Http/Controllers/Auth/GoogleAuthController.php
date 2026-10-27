<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleAccounts;
use App\Auth\GoogleAuthConfig;
use App\Auth\GoogleIdentity;
use App\Auth\SafeRedirect;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ConfirmGoogleSignUp;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    public const PENDING_SIGNUP_SESSION_KEY = 'google_pending_signup';

    public const PENDING_SIGNUP_LIFETIME_MINUTES = 60;

    private const INTENT_SESSION_KEY = 'google_intent';

    private const REDIRECT_SESSION_KEY = 'google_redirect';

    public function __construct(
        private readonly GoogleAuthConfig $config,
        private readonly GoogleAccounts $accounts,
    ) {}

    /**
     * Ties a linking request to the account's current credentials and status:
     * a password reset (new hash and remember token) or disabling the account
     * invalidates any request started before it.
     */
    public static function linkFingerprint(User $user): string
    {
        return hash_hmac('sha256', implode("\0", [
            (string) $user->getKey(),
            (string) $user->getAuthPassword(),
            (string) $user->getRememberToken(),
            (string) $user->email,
            (string) $user->status,
        ]), (string) config('app.key'));
    }

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
            return redirect(SafeRedirect::defaultFor($request->user()));
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

    /**
     * The link from the confirmation email for a pending Google sign-up. It
     * only completes in the browser session that started the sign-up.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING_SIGNUP_SESSION_KEY);
        $token = $request->query('token');

        if (! $this->config->available()) {
            return redirect('/login?google=unavailable');
        }

        if (! is_array($pending) || ! is_string($token)
            || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()
            || ! hash_equals((string) ($pending['token_hash'] ?? ''), hash('sha256', $token))) {
            return redirect('/login?google=confirm_invalid');
        }

        $request->session()->forget(self::PENDING_SIGNUP_SESSION_KEY);

        return $this->completeSignIn($request, GoogleIdentity::fromSession($pending['identity']), emailConfirmed: true);
    }

    private function completeSignIn(Request $request, GoogleIdentity $identity, bool $emailConfirmed = false): RedirectResponse
    {
        [$outcome, $user] = $this->accounts->signIn($identity, $emailConfirmed);

        if ($outcome === GoogleAccounts::CONFIRMATION_REQUIRED) {
            $this->startEmailConfirmation($request, $identity);

            return redirect('/login?google='.$outcome);
        }

        if ($user === null) {
            return redirect('/login?google='.$outcome);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect(SafeRedirect::afterLogin($request, $request->session()->pull(self::REDIRECT_SESSION_KEY)));
    }

    /**
     * Google is not authoritative for this address: nothing is created yet.
     * The claims wait in this session, and a one-time link goes to the
     * address. At most three such emails per address per hour.
     */
    private function startEmailConfirmation(Request $request, GoogleIdentity $identity): void
    {
        $token = Str::random(64);
        $request->session()->put(self::PENDING_SIGNUP_SESSION_KEY, [
            'identity' => $identity->toSession(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::PENDING_SIGNUP_LIFETIME_MINUTES)->getTimestamp(),
        ]);

        RateLimiter::attempt('google-confirm:'.sha1($identity->email), 3, function () use ($identity, $token): void {
            try {
                Notification::route('mail', $identity->email)
                    ->notify(new ConfirmGoogleSignUp(url('/auth/google/confirm').'?'.http_build_query(['token' => $token])));
            } catch (Throwable $exception) {
                report($exception);
            }
        }, 3600);
    }

    private function completeLink(Request $request, GoogleIdentity $identity): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login?account=disabled');
        }

        if (! $this->hasPendingLink($request)) {
            $request->session()->forget(self::LINK_SESSION_KEY);

            return redirect('/account?google=link_expired');
        }

        $request->session()->forget(self::LINK_SESSION_KEY);

        return redirect('/account?google='.$this->accounts->link($user, $identity));
    }

    /**
     * Linking was requested, with the password confirmed, by the account that
     * is signed in now, a few minutes ago, and its credentials and status have
     * not changed since (password reset, disabling, another account).
     */
    private function hasPendingLink(Request $request): bool
    {
        $pending = $request->session()->get(self::LINK_SESSION_KEY);
        $user = $request->user();

        return is_array($pending)
            && $user !== null
            && $user->isActive()
            && (int) ($pending['user_id'] ?? 0) === (int) $user->getKey()
            && (int) ($pending['expires_at'] ?? 0) >= now()->getTimestamp()
            && hash_equals((string) ($pending['fingerprint'] ?? ''), self::linkFingerprint($user));
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
