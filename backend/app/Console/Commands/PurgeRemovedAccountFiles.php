<?php

namespace App\Console\Commands;

use App\Admin\RemovalFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:purge-files {--limit=100 : Maximum queued paths to retry}')]
#[Description('Retry physical deletion of files unlinked by completed account removals')]
class PurgeRemovedAccountFiles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RemovalFiles $files): int
    {
        $remaining = $files->drain(max(1, min(1000, (int) $this->option('limit'))));
        $this->line("Pending file removals: {$remaining}");

        return $remaining === 0 ? self::SUCCESS : self::FAILURE;
    }
}
