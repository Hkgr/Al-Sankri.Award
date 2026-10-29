<?php

namespace App\Admin;

use App\Candidates\NominationCycles;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The two schedule windows the administration edits in «المواعيد»:
 * registration (`nomination_details`) and judging (`judging`). Times are
 * stored in UTC and shown in the cycle's timezone (Asia/Damascus for the
 * first cycle). A stage is open when it is enabled and now is inside its
 * window; a missing judging stage is treated as closed so scoring cannot
 * run until the window is set.
 */
class CycleSchedule
{
    public const NOMINATION = NominationCycles::STAGE_CODE;

    public const JUDGING = 'judging';

    public const NOMINATION_NAME = 'فترة الترشح والتسجيل';

    public const JUDGING_NAME = 'فترة التحكيم';

    public const UPDATED = 'updated';

    public const CYCLE_MISSING = 'cycle_missing';

    public const INVALID_WINDOW = 'invalid_window';

    public const JUDGING_BEFORE_NOMINATION = 'judging_before_nomination';

    /**
     * @return array{cycle: array{id: int, code: string, name: string, timezone: string}, nomination: array<string, mixed>, judging: array<string, mixed>}|null
     */
    public function forCycle(int $cycleId): ?array
    {
        $cycle = DB::table('award_cycles')->where('id', $cycleId)->first(['id', 'code', 'name_ar', 'timezone']);
        if ($cycle === null) {
            return null;
        }

        return [
            'cycle' => [
                'id' => (int) $cycle->id,
                'code' => $cycle->code,
                'name' => $cycle->name_ar,
                'timezone' => $cycle->timezone,
            ],
            'nomination' => $this->present($cycle, self::NOMINATION, self::NOMINATION_NAME),
            'judging' => $this->present($cycle, self::JUDGING, self::JUDGING_NAME),
        ];
    }

    public function nominationOpen(int $cycleId, bool $lock = false): bool
    {
        return app(NominationCycles::class)->stageOpenFor($cycleId, $lock);
    }

    public function judgingOpen(int $cycleId, bool $lock = false): bool
    {
        return $this->stageOpen($cycleId, self::JUDGING, $lock);
    }

    /**
     * @param  array{nomination_opens_on: string, nomination_closes_on: string, judging_opens_on: string, judging_closes_on: string}  $input
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function update(User $actor, int $cycleId, array $input): array
    {
        return DB::transaction(function () use ($actor, $cycleId, $input): array {
            DB::table('users')->where('id', $actor->getKey())->sharedLock()->first();
            $cycle = DB::table('award_cycles')->where('id', $cycleId)->lockForUpdate()->first(['id', 'code', 'name_ar', 'timezone']);
            if ($cycle === null) {
                return [self::CYCLE_MISSING, null];
            }

            try {
                $nominationOpens = $this->dayStart($input['nomination_opens_on'], $cycle->timezone);
                $nominationCloses = $this->dayAfter($input['nomination_closes_on'], $cycle->timezone);
                $judgingOpens = $this->dayStart($input['judging_opens_on'], $cycle->timezone);
                $judgingCloses = $this->dayAfter($input['judging_closes_on'], $cycle->timezone);
            } catch (InvalidArgumentException) {
                return [self::INVALID_WINDOW, null];
            }

            if (! $nominationCloses->greaterThan($nominationOpens) || ! $judgingCloses->greaterThan($judgingOpens)) {
                return [self::INVALID_WINDOW, null];
            }
            if ($judgingOpens->lessThan($nominationCloses)) {
                return [self::JUDGING_BEFORE_NOMINATION, null];
            }

            $this->writeStage($actor, $cycle, self::NOMINATION, self::NOMINATION_NAME, 2, $nominationOpens, $nominationCloses);
            $this->writeStage($actor, $cycle, self::JUDGING, self::JUDGING_NAME, 3, $judgingOpens, $judgingCloses);

            return [self::UPDATED, $this->forCycle($cycleId)];
        });
    }

    /**
     * Ensures a judging stage exists and is enabled for tests / setup that
     * already opened nomination. A null window means always open while enabled.
     */
    public function ensureJudgingStage(int $cycleId, User $actor, ?Carbon $opensAt = null, ?Carbon $closesAt = null): void
    {
        $cycle = DB::table('award_cycles')->where('id', $cycleId)->first(['id', 'timezone']);
        if ($cycle === null) {
            return;
        }

        $this->writeStage($actor, $cycle, self::JUDGING, self::JUDGING_NAME, 3, $opensAt, $closesAt);
    }

    private function stageOpen(int $cycleId, string $code, bool $lock): bool
    {
        $now = now();

        return DB::table('award_cycles as cycles')
            ->join('cycle_stages as stages', function ($join) use ($code): void {
                $join->on('stages.cycle_id', '=', 'cycles.id')->where('stages.code', '=', $code);
            })
            ->where('cycles.id', $cycleId)
            ->where('cycles.status', 'published')
            ->where('stages.status', 'enabled')
            ->where(fn ($query) => $query->whereNull('stages.opens_at')->orWhere('stages.opens_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('stages.closes_at')->orWhere('stages.closes_at', '>', $now))
            ->when($lock, fn ($query) => $query->sharedLock())
            ->first(['cycles.id']) !== null;
    }

    /**
     * @return array{code: string, name: string, status: string|null, open: bool, opens_at: string|null, closes_at: string|null, opens_on: string|null, closes_on: string|null}
     */
    private function present(object $cycle, string $code, string $fallbackName): array
    {
        $stage = DB::table('cycle_stages')->where('cycle_id', $cycle->id)->where('code', $code)->first();
        $opensAt = $stage?->opens_at;
        $closesAt = $stage?->closes_at;

        return [
            'code' => $code,
            'name' => $stage->name_ar ?? $fallbackName,
            'status' => $stage->status ?? null,
            'open' => $stage !== null && $this->stageOpen((int) $cycle->id, $code, false),
            'opens_at' => $opensAt === null ? null : Carbon::parse($opensAt, 'UTC')->toIso8601ZuluString(),
            'closes_at' => $closesAt === null ? null : Carbon::parse($closesAt, 'UTC')->toIso8601ZuluString(),
            'opens_on' => $opensAt === null ? null : Carbon::parse($opensAt, 'UTC')->timezone($cycle->timezone)->toDateString(),
            'closes_on' => $closesAt === null ? null : Carbon::parse($closesAt, 'UTC')->timezone($cycle->timezone)->subSecond()->toDateString(),
        ];
    }

    private function writeStage(User $actor, object $cycle, string $code, string $name, int $order, ?Carbon $opensAt, ?Carbon $closesAt): void
    {
        $stage = DB::table('cycle_stages')->where('cycle_id', $cycle->id)->where('code', $code)->lockForUpdate()->first();
        $now = now();
        $opens = $opensAt?->clone()->utc()->format('Y-m-d H:i:s');
        $closes = $closesAt?->clone()->utc()->format('Y-m-d H:i:s');
        $window = [
            'opens_at' => $opens,
            'closes_at' => $closes,
            'last_change_reason' => 'admin schedule',
            'updated_at' => $now,
        ];

        if ($stage === null) {
            $id = DB::table('cycle_stages')->insertGetId($window + [
                'cycle_id' => $cycle->id,
                'code' => $code,
                'name_ar' => $name,
                'status' => 'enabled',
                'display_order' => $order,
                'enabled_by' => $actor->getKey(),
                'enabled_at' => $now,
                'created_at' => $now,
            ]);
            AuditLog::record('cycle_stage.created', (int) $actor->getKey(), 'cycle_stage', $id, ['code' => $code], (int) $cycle->id);
            AuditLog::record('cycle_stage.enabled', (int) $actor->getKey(), 'cycle_stage', $id, ['code' => $code], (int) $cycle->id);

            return;
        }

        $changed = (string) $stage->opens_at !== (string) $opens
            || (string) $stage->closes_at !== (string) $closes
            || $stage->status !== 'enabled';

        if (! $changed) {
            return;
        }

        $update = $window + ['name_ar' => $name];
        if ($stage->status !== 'enabled') {
            $update += ['status' => 'enabled', 'enabled_by' => $actor->getKey(), 'enabled_at' => $now];
        }
        DB::table('cycle_stages')->where('id', $stage->id)->update($update);
        AuditLog::record('cycle_stage.window_changed', (int) $actor->getKey(), 'cycle_stage', (int) $stage->id, [
            'code' => $code,
            'opens_at' => $opensAt?->clone()->utc()->toIso8601ZuluString(),
            'closes_at' => $closesAt?->clone()->utc()->toIso8601ZuluString(),
        ], (int) $cycle->id);
        if ($stage->status !== 'enabled') {
            AuditLog::record('cycle_stage.enabled', (int) $actor->getKey(), 'cycle_stage', (int) $stage->id, ['code' => $code], (int) $cycle->id);
        }
    }

    private function dayStart(string $date, string $timezone): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('date');
        }
        $time = Carbon::createFromFormat('Y-m-d', $date, $timezone);
        if ($time === false || $time->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('date');
        }

        return $time->startOfDay();
    }

    private function dayAfter(string $date, string $timezone): Carbon
    {
        return $this->dayStart($date, $timezone)->addDay();
    }
}
