<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Maps verified Google identities to accounts.
 *
 * The Google subject is the identity key. An identity is only ever stored
 * against an account whose email ownership is established, so a Google
 * identity can never be planted on an address its holder does not own:
 *
 * - a new account is created only when Google is authoritative for the
 *   address, or after the address was confirmed by an emailed link opened
 *   in the browser that started the sign-in ($emailConfirmed);
 * - linking to an existing account needs that account's email to be
 *   verified already, or a Google identity authoritative for that same
 *   address;
 * - a matching email alone never attaches Google to an existing account.
 */
class GoogleAccounts
{
    public const PROVIDER = 'google';

    public const SIGNED_IN = 'signed_in';

    public const CREATED = 'created';

    public const CONFIRMATION_REQUIRED = 'confirm_email';

    public const ACCOUNT_EXISTS = 'account_exists';

    public const DISABLED = 'disabled';

    public const EMAIL_UNVERIFIED = 'email_unverified';

    public const LINKED = 'linked';

    public const ALREADY_LINKED = 'already_linked';

    public const OWNED_BY_OTHER = 'owned_by_other';

    public const USER_HAS_OTHER = 'user_has_other';

    /**
     * Resolves a Google sign-in to an existing linked account or a new one.
     *
     * @param  bool  $emailConfirmed  The address was proven by this site's
     *                                own emailed link for this pending sign-up.
     * @return array{0: string, 1: User|null} An outcome constant, and the
     *                                        account to sign in on success.
     */
    public function signIn(GoogleIdentity $identity, bool $emailConfirmed = false): array
    {
        if ($user = $this->userForSubject($identity->subject)) {
            return $this->signInLinked($user, $identity);
        }

        if (User::query()->where('email', $identity->email)->exists()) {
            return [self::ACCOUNT_EXISTS, null];
        }

        if (! $identity->emailIsAuthoritative() && ! $emailConfirmed) {
            return [self::CONFIRMATION_REQUIRED, null];
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
     * Links a Google identity to an account whose owner confirmed their
     * local password and asked for the link. The caller must re-check, at
     * the callback, that this request is still current (see
     * GoogleAuthController); this method checks email ownership.
     */
    public function link(User $user, GoogleIdentity $identity): string
    {
        if ($outcome = $this->linkConflict($user, $identity)) {
            return $outcome;
        }

        $provesSameEmail = $identity->emailIsAuthoritative() && $identity->email === $user->email;

        if (! $user->hasVerifiedEmail() && ! $provesSameEmail) {
            return self::EMAIL_UNVERIFIED;
        }

        try {
            $this->insertIdentity($user, $identity);
        } catch (UniqueConstraintViolationException) {
            return $this->linkConflict($user, $identity) ?? self::USER_HAS_OTHER;
        }

        if (! $user->hasVerifiedEmail()) {
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

        // Every identity is stored against a proven address; refuse anything
        // else rather than trust it.
        if (! $user->hasVerifiedEmail()) {
            return [self::EMAIL_UNVERIFIED, null];
        }

        DB::table('oauth_identities')
            ->where('provider', self::PROVIDER)
            ->where('provider_subject', $identity->subject)
            ->update(['last_login_at' => now(), 'updated_at' => now()]);

        return [self::SIGNED_IN, $user];
    }

    /**
     * Only called once the address is proven, so the account is verified.
     * Runs inside the caller's transaction: the account, its identity and
     * its candidate classification are created together or not at all.
     */
    private function createAccount(GoogleIdentity $identity): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $identity->name,
            'email' => $identity->email,
            'password' => null,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ])->save();

        $this->insertIdentity($user, $identity);
        app(CandidateRole::class)->grant($user, CandidateRole::SOURCE_GOOGLE);

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
