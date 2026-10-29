<?php

namespace App\Candidates;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The award's public schedule for the website's countdown, read from the
 * existing cycle and stage configuration (nomination:setup). Display only:
 * nothing here opens, closes, refers, approves or changes any permission.
 *
 * - The application window is the `nomination_details` stage: the same row
 *   and cutoff that Stage 2, Stage 3 and final submission enforce. Open
 *   applications also require NominationCycles::current(), including the
 *   configured geography, domain options and unambiguous cycle selection.
 * - The judging period is the cycle's optional `judging` stage, an
 *   informational window (always `planned`; the judge portal never reads it).
 *
 * The cycle shown is the published one available for nomination now;
 * otherwise the published cycle with the latest configured nomination stage
 * (enabled or closed). Times are UTC instants (ISO 8601, Z); `server_time`
 * lets the page align its clock.
 */
class PublicSchedule
{
    public const JUDGING_STAGE = 'judging';

    public function __construct(private readonly NominationCycles $cycles) {}

    /**
     * @return array{server_time: string, cycle: array{name: string, year: int, timezone: string}|null, application: array{opens_at: string|null, closes_at: string|null, open: bool}|null, judging: array{opens_at: string|null, closes_at: string|null}|null}
     */
    public function current(): array
    {
        $empty = ['server_time' => $this->iso(now()), 'cycle' => null, 'application' => null, 'judging' => null];

        $candidates = DB::table('award_cycles as cycles')
            ->join('cycle_stages as stages', function ($join): void {
                $join->on('stages.cycle_id', '=', 'cycles.id')->where('stages.code', '=', NominationCycles::STAGE_CODE);
            })
            ->where('cycles.status', 'published')
            ->whereIn('stages.status', ['enabled', 'closed'])
            ->orderByDesc('stages.closes_at')->orderByDesc('cycles.id')
            ->get(['cycles.id', 'cycles.name_ar', 'cycles.year', 'cycles.timezone', 'stages.opens_at', 'stages.closes_at']);
        if ($candidates->isEmpty()) {
            return $empty;
        }

        $availability = $this->cycles->current();
        if ($availability['status'] === NominationCycles::AMBIGUOUS) {
            return $empty;
        }
        $available = $availability['cycle'];
        $cycle = $available === null ? $candidates->first() : $candidates->firstWhere('id', $available->id);

        $judging = DB::table('cycle_stages')->where('cycle_id', $cycle->id)->where('code', self::JUDGING_STAGE)
            ->whereNotIn('status', ['cancelled'])->first(['opens_at', 'closes_at']);

        return [
            'server_time' => $this->iso(now()),
            'cycle' => ['name' => $cycle->name_ar, 'year' => (int) $cycle->year, 'timezone' => $cycle->timezone],
            'application' => [
                'opens_at' => $this->iso($cycle->opens_at),
                'closes_at' => $this->iso($cycle->closes_at),
                'open' => $available !== null,
            ],
            'judging' => $judging === null ? null : ['opens_at' => $this->iso($judging->opens_at), 'closes_at' => $this->iso($judging->closes_at)],
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->utc()->toIso8601ZuluString();
    }
}
