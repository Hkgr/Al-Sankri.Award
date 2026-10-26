<?php

namespace App\Auth;

/**
 * Whether Google sign-in may be offered: it must be switched on with
 * GOOGLE_AUTH_ENABLED and have a client ID, secret and redirect URI.
 */
class GoogleAuthConfig
{
    public function available(): bool
    {
        $config = config('services.google');

        return ($config['enabled'] ?? false) === true
            && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && filled($config['redirect'] ?? null);
    }
}
