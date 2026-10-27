<?php

namespace App\Candidates;

use App\Models\CandidateProfile;
use App\Support\SyrianGovernorates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether Stage 2 can be used, and in which cycle.
 *
 * A cycle is usable only when an operator has configured it explicitly
 * (`php artisan nomination:setup`):
 *
 * - the cycle is published;
 * - its stage `nomination_details` is enabled, and now is inside the
 *   stage's window when one is set;
 * - its geography (award_cycles.eligibility_rules.implementation_country)
 *   names a country with a vetted governorate list (currently Syria);
 * - at least one approved domain is open, with at least one active
 *   subcategory.
 *
 * Exactly one usable cycle is required. None, or more than one, makes the
 * stage unavailable: the first one is never chosen by default. Opening this
 * stage opens nothing else (no submission stage, no versions).
 */
class NominationCycles
{
    public const STAGE_CODE = 'nomination_details';

    public const AVAILABLE = 'available';

    public const NOT_CONFIGURED = 'not_configured';

    public const AMBIGUOUS = 'ambiguous';

    /** Countries for which a vetted list of governorates exists. */
    public const SUPPORTED_COUNTRIES = [SyrianGovernorates::COUNTRY];

    /**
     * @return array{status: string, cycle: object|null}
     */
    public function current(): array
    {
        $now = now();
        $candidates = DB::table('award_cycles as cycles')
            ->join('cycle_stages as stages', function ($join): void {
                $join->on('stages.cycle_id', '=', 'cycles.id')->where('stages.code', '=', self::STAGE_CODE);
            })
            ->where('cycles.status', 'published')
            ->where('stages.status', 'enabled')
            ->where(fn ($query) => $query->whereNull('stages.opens_at')->orWhere('stages.opens_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('stages.closes_at')->orWhere('stages.closes_at', '>', $now))
            ->orderBy('cycles.id')
            ->get(['cycles.id', 'cycles.code', 'cycles.name_ar', 'cycles.timezone', 'cycles.eligibility_rules', 'stages.id as stage_id'])
            ->filter(fn (object $cycle): bool => $this->country($cycle) !== null && $this->options((int) $cycle->id)->isNotEmpty())
            ->values();

        return match ($candidates->count()) {
            0 => ['status' => self::NOT_CONFIGURED, 'cycle' => null],
            1 => ['status' => self::AVAILABLE, 'cycle' => $candidates->first()],
            default => ['status' => self::AMBIGUOUS, 'cycle' => null],
        };
    }

    /**
     * The supported implementation country configured for the cycle, or null.
     */
    public function country(object $cycle): ?string
    {
        $rules = is_string($cycle->eligibility_rules) ? json_decode($cycle->eligibility_rules, true) : $cycle->eligibility_rules;
        $country = is_array($rules) ? ($rules['implementation_country'] ?? null) : null;

        return in_array($country, self::SUPPORTED_COUNTRIES, true) ? $country : null;
    }

    /**
     * The open approved domains of a cycle, each with its active
     * subcategories, in display order. Domains without an active
     * subcategory are left out.
     *
     * @return Collection<int, array{cycle_domain_id: int, code: string, name: string, artwork: string, subcategories: list<array{id: int, code: string, name: string}>}>
     */
    public function options(int $cycleId, bool $lock = false): Collection
    {
        $domains = DB::table('cycle_domains')
            ->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->where('cycle_domains.cycle_id', $cycleId)
            ->where('cycle_domains.is_open_for_applications', true)
            ->where('award_domains.is_active', true)
            ->whereIn('award_domains.code', NominationTaxonomy::domainCodes())
            ->orderBy('cycle_domains.display_order')
            ->orderBy('cycle_domains.id')
            ->when($lock, fn ($query) => $query->sharedLock())
            ->get(['cycle_domains.id', 'cycle_domains.name_ar', 'award_domains.code']);

        $subcategories = DB::table('cycle_domain_subcategories')
            ->whereIn('cycle_domain_id', $domains->pluck('id'))
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->sharedLock())
            ->get(['id', 'cycle_domain_id', 'code', 'name_ar'])
            ->groupBy('cycle_domain_id');

        return $domains
            ->map(fn (object $domain): array => [
                'cycle_domain_id' => (int) $domain->id,
                'code' => $domain->code,
                'name' => $domain->name_ar,
                'artwork' => (string) NominationTaxonomy::artworkFor($domain->code),
                'subcategories' => ($subcategories->get($domain->id) ?? collect())
                    ->map(fn (object $subcategory): array => ['id' => (int) $subcategory->id, 'code' => $subcategory->code, 'name' => $subcategory->name_ar])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $domain): bool => $domain['subcategories'] !== [])
            ->values();
    }

    /**
     * The journey key of a candidate's Stage 2 in a cycle: an opaque,
     * server-issued value the form sends back, so every check and the
     * confirmation address the same journey. It changes when the usable
     * cycle changes, so a form opened for another cycle is refused.
     */
    public function journeyKey(CandidateProfile $profile, int $cycleId): string
    {
        return hash_hmac('sha256', 'candidate-nomination|'.$profile->getKey().'|'.$cycleId, (string) config('app.key'));
    }
}
