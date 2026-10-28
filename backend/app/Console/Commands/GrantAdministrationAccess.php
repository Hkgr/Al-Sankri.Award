<?php

namespace App\Console\Commands;

use App\Admin\AdminAccess;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gives one existing account administration access (the `award_admin`
 * role), named explicitly by its email. Nothing else ever grants it: not
 * registration, not the first account, not an id or an email domain.
 *
 * Idempotent: an account that already holds the role is left unchanged.
 * Disabled accounts are refused. --dry-run reports and changes nothing. The
 * grant is recorded in user_roles and audit_logs (source
 * admin_grant_access_command); existing roles and history are kept.
 */
#[Signature('admin:grant-access
    {--email= : Email of the existing account to give administration access}
    {--dry-run : Show what would change, and change nothing}')]
#[Description('Give administration access (award_admin) to one existing account, by email')]
class GrantAdministrationAccess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AdminAccess $access): int
    {
        $email = Str::lower(trim((string) $this->option('email')));

        if ($email === '') {
            $this->error('Give --email= with the email of an existing account.');

            return self::INVALID;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error('No account uses this email. The account must exist first (it is never created here).');

            return self::FAILURE;
        }

        try {
            $granted = $access->grantAdmin($user, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $granted) {
            $this->info("Account #{$user->getKey()} already has administration access. Nothing changed.");
        } elseif ($this->option('dry-run')) {
            $this->info("Dry run: account #{$user->getKey()} would be given administration access. Nothing was changed.");
        } else {
            $this->info("Account #{$user->getKey()} now has administration access.");
        }

        return self::SUCCESS;
    }
}
