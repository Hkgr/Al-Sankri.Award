<?php

namespace App\Console\Commands;

use App\Candidates\ApplicationProgress;
use App\Models\Application;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('progress:initialize {cycle : Exact existing cycle code} {--apply : Persist a silent baseline; default is preview only}')]
#[Description('Preview or initialize candidate progress from saved facts, without historical emails')]
class InitializeCandidateProgress extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ApplicationProgress $progress): int
    {
        $cycle = DB::table('award_cycles')->where('code', (string) $this->argument('cycle'))->first();
        if ($cycle === null) {
            $this->error('Select an exact existing cycle code.');

            return self::INVALID;
        }
        $counts = [];
        Application::query()->where('cycle_id', $cycle->id)->whereNotNull('current_version_id')->orderBy('id')
            ->chunkById(100, function ($applications) use ($cycle, $progress, &$counts): void {
                foreach ($applications as $application) {
                    DB::transaction(function () use ($cycle, $application, $progress, &$counts): void {
                        DB::table('award_cycles')->where('id', $cycle->id)->sharedLock()->first();
                        $observed = DB::table('applications')->where('id', $application->id)->first(['review_committee_id']);
                        if ($observed?->review_committee_id !== null) {
                            DB::table('committees')->where('id', $observed->review_committee_id)->lockForUpdate()->first();
                        }
                        $locked = Application::query()->whereKey($application->id)->lockForUpdate()->first();
                        if ($locked === null || (int) $locked->cycle_id !== (int) $cycle->id) {
                            return;
                        }
                        if ((int) $locked->review_committee_id !== (int) $observed?->review_committee_id) {
                            $counts['changed_during_scan'] = ($counts['changed_during_scan'] ?? 0) + 1;

                            return;
                        }
                        $facts = $progress->facts($locked);
                        if ($facts === null) {
                            return;
                        }
                        $exists = DB::table('application_progress_events')->where('application_version_id', $locked->current_version_id)->exists();
                        $key = $exists ? 'already_initialized' : $facts['stage'];
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                        if ($this->option('apply') && ! $exists) {
                            $progress->record((int) $locked->id, historical: true);
                        }
                    }, 3);
                }
            });
        $this->line("Cycle: {$cycle->code} (ID {$cycle->id})");
        $this->line(json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info($this->option('apply') ? 'Silent baseline applied; no historical notifications.' : 'Preview only; no data changed and no notifications.');

        return self::SUCCESS;
    }
}
