<?php

namespace App\Candidates;

use App\Models\User;

/**
 * Binds an open stage 1 form to the account it was opened for.
 *
 * The form receives an opaque value with GET /api/candidate/basic-profile
 * and sends it back with every check and confirmation. If the browser's
 * session now belongs to another account (for example after signing in as
 * someone else in another tab), the value no longer matches and the request
 * is refused before anything is validated, written or returned.
 *
 * The value is only ever compared with the signed-in account's own value;
 * it never selects an account. It is an HMAC of the account id with the
 * application key, so it does not reveal the id.
 */
final class AccountContext
{
    public static function for(User $user): string
    {
        return hash_hmac('sha256', 'candidate-basic-profile|'.$user->getKey(), (string) config('app.key'));
    }

    public static function matches(User $user, mixed $context): bool
    {
        return is_string($context) && hash_equals(self::for($user), $context);
    }
}
