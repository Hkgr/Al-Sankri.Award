<?php

namespace App\Auth;

use App\Admin\AdminAccess;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Chooses where to send someone after they sign in.
 *
 * Only same-site paths on an explicit allowlist are accepted, so a crafted
 * "redirect" value or intended URL can never send a visitor to another site.
 */
class SafeRedirect
{
    /** Where candidates land after signing in or registering. */
    public const DASHBOARD = '/dashboard';

    /** The account page, for accounts that are not candidates (judges included). */
    public const ACCOUNT = '/account';

    /** The administration dashboard, for accounts with administration access. */
    public const ADMIN = '/admin';

    /**
     * Paths (exact) and path prefixes (ending in "/") a visitor may return to.
     *
     * @var list<string>
     */
    private const ALLOWED = ['/dashboard', '/dashboard/basic-information', '/account', '/email/verify/', '/admin', '/admin/'];

    /**
     * The default destination for an account: the administration dashboard
     * for administrators; the account page for judges (there is no judge
     * portal yet, and a judge is not sent to the candidate dashboard); the
     * candidate dashboard for candidates; otherwise the account page.
     */
    public static function defaultFor(?User $user): string
    {
        if ($user === null) {
            return self::ACCOUNT;
        }
        $access = app(AdminAccess::class);
        if ($access->isAdmin($user)) {
            return self::ADMIN;
        }
        if ($access->isJudge((int) $user->getKey())) {
            return self::ACCOUNT;
        }

        return app(CandidateRole::class)->isHeldBy($user) ? self::DASHBOARD : self::ACCOUNT;
    }

    /**
     * Returns the candidate when it is an allowed same-site path, otherwise null.
     */
    public static function path(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || strlen($candidate) > 2000) {
            return null;
        }

        // A single leading slash only: rejects "//host", "/\host", schemes and controls.
        if (preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $candidate) !== 1) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '';
        foreach (self::ALLOWED as $allowed) {
            if ($path === $allowed || (str_ends_with($allowed, '/') && str_starts_with($path, $allowed))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Converts Laravel's absolute "intended" URL into an allowed path, if it
     * points at this host.
     */
    public static function fromIntended(?string $url, Request $request): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return self::path($path);
    }

    /**
     * The destination after a successful sign-in: the intended URL stored by
     * Laravel (for example a signed verification link), then the requested
     * path, then the signed-in account's default destination.
     */
    public static function afterLogin(Request $request, mixed $requested = null): string
    {
        $intended = $request->session()->pull('url.intended');
        $user = $request->user();
        // The administration pages are a destination for administrators only.
        $usable = fn (?string $path): ?string => $path !== null && preg_match('#^/admin(/|\?|$)#', $path) === 1
            && ($user === null || ! app(AdminAccess::class)->isAdmin($user)) ? null : $path;

        return $usable(self::fromIntended(is_string($intended) ? $intended : null, $request))
            ?? $usable(self::path($requested))
            ?? self::defaultFor($user);
    }
}
