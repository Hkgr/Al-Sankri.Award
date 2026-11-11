<?php

namespace App\Candidates;

use App\Judging\Assignments;
use App\Judging\CommitteeDecision;
use App\Models\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Candidate-safe projection. Administrative and judging objects never leave this service. */
class ApplicationProgress
{
    public const ADMINISTRATIVE = 'administrative_review';

    public const COMMITTEE = 'committee_review';

    public const COMPLETE = 'review_completed';

    public const PAUSED = 'tracking_paused';

    /** @return array{stage: string, via_committee: bool, at: mixed}|null */
    public function facts(Application $application): ?array
    {
        $version = DB::table('application_versions')->where('id', $application->current_version_id)
            ->where('application_id', $application->id)->first();
        if ($version === null || ! $application->reference_number || $version->submitted_at === null) {
            return null;
        }
        $paused = ['stage' => self::PAUSED, 'via_committee' => false, 'at' => null];
        if (in_array($application->status, ['draft', 'withdrawn'], true)) {
            return $paused;
        }
        if ($application->review_status === Application::REVIEW_EXCLUDED) {
            return ['stage' => self::COMPLETE, 'via_committee' => false, 'at' => $application->review_decided_at];
        }
        if (($application->review_status ?? Application::REVIEW_PENDING) === Application::REVIEW_PENDING) {
            return $application->status === Application::STATUS_SUBMITTED ? ['stage' => self::ADMINISTRATIVE, 'via_committee' => false, 'at' => $version->submitted_at] : $paused;
        }
        $committee = DB::table('committees')->where('id', $application->review_committee_id)
            ->where('cycle_id', $application->cycle_id)->where('cycle_domain_id', $application->cycle_domain_id)
            ->where('type', 'judging')->where('status', 'approved')->whereNotNull('approved_by')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
        if ($application->review_status !== Application::REVIEW_REFERRED || $committee === null || $application->taxonomy_review_required
            || ! app(Assignments::class)->delivered((int) $application->id, (int) $committee->id)) {
            return $paused;
        }
        $decision = app(CommitteeDecision::class)->forApplication($application);
        if (! $decision['ready'] || ! in_array($decision['expected'], [3, 5], true)) {
            return $paused;
        }
        if ($decision['decision'] !== null || $decision['excluded']) {
            $at = collect($decision['scores'])->reject(fn (array $row): bool => $row['historical'])->max('sent_at');

            return ['stage' => self::COMPLETE, 'via_committee' => true, 'at' => $this->readyAt($at, $committee->approved_at)];
        }
        $assignedAt = DB::table('judging_assignments')->where('application_version_id', $version->id)
            ->where('committee_id', $committee->id)->where('round_number', 1)
            ->whereIn('committee_member_id', DB::table('committee_members')->where('committee_id', $committee->id)->whereNull('left_at')->select('id'))
            ->max('assigned_at');

        if (! in_array($application->status, [Application::STATUS_SUBMITTED, Application::STATUS_IN_JUDGING], true)) {
            return $paused;
        }

        return ['stage' => self::COMMITTEE, 'via_committee' => true, 'at' => $this->readyAt($assignedAt, $committee->approved_at)];
    }

    private function readyAt(mixed $evidenceAt, mixed $approvedAt): ?string
    {
        return $evidenceAt === null || $approvedAt === null ? null : Carbon::parse($evidenceAt, 'UTC')->max(Carbon::parse($approvedAt, 'UTC'))->toIso8601ZuluString();
    }

    /** Caller holds the domain committee (if any), then application, in the originating transaction. */
    public function record(int $applicationId, bool $historical = false): ?int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Record progress with the originating write transaction.');
        }
        $application = Application::query()->whereKey($applicationId)->lockForUpdate()->first();
        if ($application === null || ($facts = $this->facts($application)) === null) {
            return null;
        }
        $latest = DB::table('application_progress_events')->where('application_version_id', $application->current_version_id)
            ->orderByDesc('sequence')->lockForUpdate()->first();
        if ($historical && $latest !== null) {
            return (int) $latest->id;
        }
        if ($latest !== null && $latest->stage === $facts['stage'] && (bool) $latest->via_committee === $facts['via_committee']) {
            return (int) $latest->id;
        }
        // Silently baseline existing files before their first new transition.
        if ($latest === null && ! $historical && $facts['stage'] !== self::ADMINISTRATIVE) {
            $version = DB::table('application_versions')->where('id', $application->current_version_id)->first();
            $this->insert($application, self::ADMINISTRATIVE, false, 1, $version->submitted_at, true);
            $sequence = 2;
        } else {
            $sequence = ($latest?->sequence ?? 0) + 1;
        }
        $id = $this->insert($application, $facts['stage'], $facts['via_committee'], $sequence, $historical ? $facts['at'] : now(), $historical);
        if (! $historical) {
            app(ProgressNotifications::class)->intend($id, $application);
        }

        return $id;
    }

    private function insert(Application $application, string $stage, bool $viaCommittee, int $sequence, mixed $at, bool $historical): int
    {
        return DB::table('application_progress_events')->insertGetId([
            'application_id' => $application->id, 'application_version_id' => $application->current_version_id,
            'cycle_id' => $application->cycle_id, 'sequence' => $sequence, 'stage' => $stage,
            'via_committee' => $viaCommittee, 'occurred_at' => $at, 'historical' => $historical, 'recorded_at' => now(),
        ]);
    }

    /** Committee already locked; visit applications in the standard ID order. */
    public function committeeChanged(int $committeeId): void
    {
        $ids = DB::table('applications')->where('review_committee_id', $committeeId)->whereNotNull('current_version_id')
            ->orderBy('id')->lockForUpdate()->pluck('id');
        foreach ($ids as $id) {
            $this->record((int) $id);
        }
    }

    /** Explicit allowlist for every candidate receipt and progress read. Reads never create events. */
    public function forApplication(Application $application): ?array
    {
        $facts = $this->facts($application);
        $cycle = DB::table('award_cycles')->where('id', $application->cycle_id)->first(['code', 'year']);
        $cycleData = ['code' => (string) $cycle->code, 'year' => (int) $cycle->year];
        if ($facts === null) {
            return $application->current_version_id === null ? null : [
                'stage' => self::PAUSED, 'reference_number' => $application->reference_number ?: null,
                'cycle' => $cycleData, 'version_number' => null, 'updated_at' => null, 'sequence' => null,
                'steps' => [['code' => self::PAUSED, 'state' => 'current', 'at' => null]],
            ];
        }
        $version = DB::table('application_versions')->where('id', $application->current_version_id)->first();
        $events = DB::table('application_progress_events')->where('application_id', $application->id)
            ->where('application_version_id', $version->id)->orderBy('sequence')->get();
        $latest = $events->last();
        $matching = $latest !== null && $latest->stage === $facts['stage'] && (bool) $latest->via_committee === $facts['via_committee'];
        $at = fn (mixed $date): ?string => $date === null ? null : Carbon::parse($date, 'UTC')->toIso8601ZuluString();
        $currentAt = $matching ? $latest->occurred_at : $facts['at'];
        $committee = $facts['via_committee'];
        $complete = $facts['stage'] === self::COMPLETE;
        $paused = $facts['stage'] === self::PAUSED;
        $hadCommittee = $committee || $events->contains(fn (object $event): bool => (bool) $event->via_committee);
        $stageAt = fn (string $stage): ?string => $at($events->last(fn (object $event): bool => $event->stage === $stage)?->occurred_at);
        $steps = [
            ['code' => 'received', 'state' => 'completed', 'at' => $at($version->submitted_at)],
            ['code' => self::ADMINISTRATIVE, 'state' => $facts['stage'] === self::ADMINISTRATIVE ? 'current' : ($paused && ! $hadCommittee ? 'paused' : 'completed'), 'at' => $at($version->submitted_at)],
        ];
        if ((! $complete && ! $paused) || $committee || $hadCommittee) {
            $steps[] = ['code' => self::COMMITTEE, 'state' => $facts['stage'] === self::COMMITTEE ? 'current' : ($complete ? 'completed' : ($paused ? 'paused' : 'waiting')), 'at' => $hadCommittee ? ($stageAt(self::COMMITTEE) ?? ($committee && ! $complete ? $at($facts['at']) : null)) : null];
        }
        if ($paused) {
            $steps[] = ['code' => self::PAUSED, 'state' => 'current', 'at' => $at($currentAt)];
        }
        if (! $paused) {
            $steps[] = ['code' => self::COMPLETE, 'state' => $complete ? 'current' : 'waiting', 'at' => $complete ? $at($currentAt) : null];
        }

        return [
            'stage' => $facts['stage'], 'reference_number' => (string) $application->reference_number,
            'cycle' => $cycleData,
            'version_number' => (int) $version->version_number, 'updated_at' => $at($currentAt),
            'sequence' => $matching ? (int) $latest->sequence : null, 'steps' => $steps,
        ];
    }
}
