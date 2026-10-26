<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Maps verified Google identities to accounts.
 *
 * The Google subject is the identity key. A Google sign-in never attaches
 * itself to an existing account just because the email matches: that needs
 * the owner to sign in locally and link Google from the account page.
 */
class GoogleAccounts
{
    public const PROVIDER = 'google';

    public const SIGNED_IN = 'signed_in';

    public const CREATED = 'created';

    public const ACCOUNT_EXISTS = 'account_exists';

    public const DISABLED = 'disabled';

    public const LINKED = 'linked';

    public const ALREADY_LINKED = 'already_linked';

    public const OWNED_BY_OTHER = 'owned_by_other';

    public const USER_HAS_OTHER = 'user_has_other';

    /**
     * Resolves a Google sign-in to an existing linked account or a new one.
     *
     * @return array{0: string, 1: User|null} An outcome constant, and the
     *                                        account to sign in on success.
     */
    public function signIn(GoogleIdentity $identity): array
    {
        if ($user = $this->userForSubject($identity->subject)) {
            return $this->signInLinked($user, $identity);
        }

        if (User::query()->where('email', $identity->email)->exists()) {
            return [self::ACCOUNT_EXISTS, null];
        }

        try {
            $user = DB::transaction(fn (): User => $this->createAccount($identity));
        } catch (UniqueConstraintViolationException) {
            // A parallel callback created this identity, or the email, first.
            if ($user = $this->userForSubject($identity->subject)) {
                return $this->signInLinked($user, $identity);
            }

            return [self::ACCOUNT_EXISTS, null];
        }

        return [self::CREATED, $user];
    }

    /**
     * Links a Google identity to an account whose owner has just confirmed
     * their local password and asked for the link.
     */
    public function link(User $user, GoogleIdentity $identity): string
    {
        if ($outcome = $this->linkConflict($user, $identity)) {
            return $outcome;
        }

        try {
            $this->insertIdentity($user, $identity);
        } catch (UniqueConstraintViolationException) {
            return $this->linkConflict($user, $identity) ?? self::USER_HAS_OTHER;
        }

        if ($identity->emailIsAuthoritative() && $identity->email === $user->email && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return self::LINKED;
    }

    public function isLinked(User $user): bool
    {
        return DB::table('oauth_identities')
            ->where('user_id', $user->getKey())
            ->where('provider', self::PROVIDER)
            ->exists();
    }

    private function linkConflict(User $user, GoogleIdentity $identity): ?string
    {
        $owner = DB::table('oauth_identities')
            ->where('provider', self::PROVIDER)
            ->where('provider_subject', $identity->subject)
            ->value('user_id');

        if ($owner !== null) {
            return (int) $owner === (int) $user->getKey() ? self::ALREADY_LINKED : self::OWNED_BY_OTHER;
        }

        return $this->isLinked($user) ? self::USER_HAS_OTHER : null;
    }

    /**
     * @return array{0: string, 1: User|null}
     */
    private function signInLinked(User $user, GoogleIdentity $identity): array
    {
        if (! $user->isActive()) {
            return [self::DISABLED, null];
        }

        DB::table('oauth_identities')
            ->where('provider', self::PROVIDER)
            ->where('provider_subject', $identity->subject)
            ->update(['last_login_at' => now(), 'updated_at' => now()]);

        return [self::SIGNED_IN, $user];
    }

    private function createAccount(GoogleIdentity $identity): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $identity->name,
            'email' => $identity->email,
            'password' => null,
            'email_verified_at' => $identity->emailIsAuthoritative() ? now() : null,
        ])->save();

        $this->insertIdentity($user, $identity);

        return $user;
    }

    private function insertIdentity(User $user, GoogleIdentity $identity): void
    {
        DB::table('oauth_identities')->insert([
            'user_id' => $user->getKey(),
            'provider' => self::PROVIDER,
            'provider_subject' => $identity->subject,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function userForSubject(string $subject): ?User
    {
        $userId = DB::table('oauth_identities')
            ->where('provider', self::PROVIDER)
            ->where('provider_subject', $subject)
            ->value('user_id');

        return $userId === null ? null : User::query()->find($userId);
    }
}
