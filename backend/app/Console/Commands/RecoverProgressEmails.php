<?php

namespace App\Console\Commands;

use App\Candidates\ProgressNotifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('progress:recover-emails {--cycle= : Limit to an exact existing cycle code} {--limit=100 : Maximum notices to enqueue} {--retry-failed : Explicitly retry exhausted deliveries}')]
#[Description('Recover committed progress notification intents using the existing asynchronous queue')]
class RecoverProgressEmails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProgressNotifications $notifications): int
    {
        $cycleId = null;
        if ($this->option('cycle') !== null) {
            $cycleId = DB::table('award_cycles')->where('code', (string) $this->option('cycle'))->value('id');
            if ($cycleId === null) {
                $this->error('Choose an exact existing cycle code.');

                return self::INVALID;
            }
        }
        $ids = DB::table('application_progress_notifications as notices')
            ->join('application_progress_events as events', 'events.id', '=', 'notices.event_id')
            ->when($cycleId !== null, fn ($query) => $query->where('events.cycle_id', $cycleId))
            ->where(function ($query): void {
                $query->whereNull('notices.last_enqueued_at')->orWhere('notices.last_enqueued_at', '<=', now()->subMinutes(5));
                if ($this->option('retry-failed')) {
                    $query->orWhere('notices.status', 'failed');
                }
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('application_progress_notifications as earlier')
                    ->join('application_progress_events as earlier_events', 'earlier_events.id', '=', 'earlier.event_id')
                    ->whereColumn('earlier_events.application_version_id', 'events.application_version_id')
                    ->whereColumn('earlier_events.sequence', '<', 'events.sequence')
                    ->whereNotIn('earlier.status', ProgressNotifications::TERMINAL);
            })
            ->where(function ($query): void {
                $query->where(fn ($query) => $query->where('notices.status', 'pending')->where('notices.available_at', '<=', now()))
                    ->orWhere(fn ($query) => $query->where('notices.status', 'processing')->where('notices.lease_until', '<=', now()));
                if ($this->option('retry-failed')) {
                    $query->orWhere('notices.status', 'failed');
                }
            })->orderBy('events.application_version_id')->orderBy('events.sequence')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('notices.id');
        foreach ($ids as $id) {
            if ($this->option('retry-failed')) {
                DB::table('application_progress_notifications')->where('id', $id)->where('status', 'failed')
                    ->update(['status' => 'pending', 'attempt_limit' => DB::raw('attempts + '.ProgressNotifications::MAX_ATTEMPTS), 'available_at' => now(), 'last_enqueued_at' => null, 'lease_token' => null, 'lease_until' => null]);
            }
            $notifications->enqueue((int) $id);
        }
        $this->info('Committed notification intents considered: '.$ids->count().'. SMTP is performed only by the asynchronous worker.');

        return self::SUCCESS;
    }
}
