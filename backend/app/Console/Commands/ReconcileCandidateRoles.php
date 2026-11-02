<?php

namespace App\Console\Commands;

use App\Auth\RoleExclusivity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('roles:reconcile-candidates {--dry-run : Preview only (the default)} {--apply : Apply the previewed policy to current state} {--user= : Limit to one numeric account ID}')]
#[Description('Reconcile the stored candidate classification with all effective platform and cycle roles')]
class ReconcileCandidateRoles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RoleExclusivity $roles): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use --dry-run or --apply, not both.');

            return self::INVALID;
        }
        $id = $this->option('user');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('--user must be a positive numeric account ID.');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $counts = $roles->reconcilePending($apply, $id === null ? null : (int) $id);
        $this->line($apply ? 'Applied to the current state under account locks.' : 'Preview only: no records or audit events changed. Use --apply to apply.');
        $this->table(['Action', 'Accounts'], collect($counts)->map(fn (int $count, string $action): array => [$action, $count])->values()->all());

        return self::SUCCESS;
    }
}
