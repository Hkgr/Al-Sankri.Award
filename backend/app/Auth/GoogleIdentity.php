<?php

namespace App\Auth;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * The identity claims Google returned to the backend for one sign-in.
 *
 * Built only from the server-side authorization-code exchange (Socialite's
 * call to Google's userinfo endpoint), never from browser-submitted data.
 */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $emailVerified,
        public readonly ?string $hostedDomain,
    ) {}

    /**
     * @throws InvalidArgumentException when Google returned no subject or email.
     */
    public static function fromSocialite(SocialiteUser $user): self
    {
        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : [];
        $subject = (string) ($raw['sub'] ?? $user->getId() ?? '');
        $email = Str::lower(trim((string) $user->getEmail()));

        if ($subject === '' || strlen($subject) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Google returned no usable subject or email.');
        }

        $name = trim((string) $user->getName());
        $hostedDomain = isset($raw['hd']) && is_string($raw['hd']) && $raw['hd'] !== '' ? Str::lower($raw['hd']) : null;

        return new self(
            subject: $subject,
            email: $email,
            name: $name !== '' ? Str::limit($name, 255, '') : Str::before($email, '@'),
            emailVerified: ($raw['email_verified'] ?? false) === true,
            hostedDomain: $hostedDomain,
        );
    }

    /**
     * Whether Google is authoritative for this address, following Google's
     * guidance: a verified address is authoritative when it is a Gmail
     * address or the account belongs to a Workspace domain (the "hd" claim).
     * Other verified addresses (for example a Google account created with a
     * third-party email) still need this site's own email verification.
     */
    public function emailIsAuthoritative(): bool
    {
        if (! $this->emailVerified) {
            return false;
        }

        return str_ends_with($this->email, '@gmail.com') || $this->hostedDomain !== null;
    }
}
