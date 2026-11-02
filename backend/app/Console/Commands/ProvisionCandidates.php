<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Compatibility entry point: all classification follows the central effective-role policy. */
#[Signature('candidates:provision {--dry-run : Preview only (the default)} {--apply : Apply reconciliation explicitly}')]
#[Description('Alias for roles:reconcile-candidates; defaults to a read-only preview')]
class ProvisionCandidates extends Command
{
    public function handle(): int
    {
        return $this->call('roles:reconcile-candidates', [
            '--dry-run' => $this->option('dry-run'),
            '--apply' => $this->option('apply'),
        ]);
    }
}
