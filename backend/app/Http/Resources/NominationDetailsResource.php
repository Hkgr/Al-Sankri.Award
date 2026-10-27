<?php

namespace App\Http\Resources;

use App\Candidates\NominationTaxonomy;
use App\Models\CandidateNomination;
use App\Support\SyrianGovernorates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * The confirmed Stage 2, for its owner only (behind auth, candidate,
 * candidate.context and no-store). The only place where the work's places
 * and funding are returned; the overview and /api/user never include them.
 *
 * @mixin CandidateNomination
 */
class NominationDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->application;
        $choice = DB::table('cycle_domains')
            ->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->join('cycle_domain_subcategories', 'cycle_domain_subcategories.cycle_domain_id', '=', 'cycle_domains.id')
            ->join('award_cycles', 'award_cycles.id', '=', 'cycle_domains.cycle_id')
            ->where('cycle_domains.id', $application->cycle_domain_id)
            ->where('cycle_domain_subcategories.id', $application->subcategory_id)
            ->first([
                'award_domains.code as domain_code', 'cycle_domains.name_ar as domain_name',
                'cycle_domain_subcategories.code as subcategory_code', 'cycle_domain_subcategories.name_ar as subcategory_name',
                'award_cycles.code as cycle_code', 'award_cycles.name_ar as cycle_name',
            ]);

        return [
            'confirmed_at' => $this->details_confirmed_at->toIso8601ZuluString(),
            'cycle' => ['code' => $choice->cycle_code, 'name' => $choice->cycle_name],
            'domain' => ['code' => $choice->domain_code, 'name' => $choice->domain_name, 'artwork' => NominationTaxonomy::artworkFor($choice->domain_code)],
            'subcategory' => ['code' => $choice->subcategory_code, 'name' => $choice->subcategory_name],
            'title' => $application->title,
            'purpose_and_mission' => $application->purpose_and_mission,
            'objectives' => $application->objectives,
            'impact' => $application->documented_impact,
            'implementation' => [
                'state' => $application->implementation_status,
                'started_on' => $application->implementation_started_on?->toDateString(),
                'ended_on' => $application->implementation_ended_on?->toDateString(),
            ],
            'locations' => $application->locations->map(fn ($location): array => [
                'country_code' => $location->country_code,
                'governorate' => $location->governorate,
                'governorate_name' => SyrianGovernorates::NAMES[$location->governorate] ?? $location->governorate,
                'locality' => $location->locality,
                'address' => $location->address,
            ])->all(),
            'funding' => [
                'type' => $application->funding_type,
                'funder_name' => $application->funder_name,
                // Exact decimal text, e.g. "1500.50"; null when not applicable.
                'amount_usd' => $application->funding_amount_usd,
            ],
            'partnerships' => [
                'has_partnerships' => $application->has_partnerships,
                'items' => $application->partnerships->map(fn ($partner): array => [
                    'name' => $partner->partner_name,
                    'contribution' => $partner->contribution,
                ])->all(),
            ],
        ];
    }
}
