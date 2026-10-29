<?php

namespace App\Http\Resources;

use App\Candidates\NominationTaxonomy;
use App\Models\CandidateNomination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * The confirmed Stage 2, for its owner only (behind auth, candidate,
 * candidate.context and no-store): the domain and subcategory and the
 * work's title, purpose and mission, objectives and impact. The overview
 * and /api/user never include the work's text. It is also the
 * nomination_details part of a submitted version's snapshot.
 *
 * @mixin CandidateNomination
 */
class NominationDetailsResource extends JsonResource
{
    /** Keys of the retired questions, as older snapshots held them. */
    public const RETIRED_KEYS = ['implementation', 'locations', 'funding', 'partnerships'];

    /**
     * Stage 2 as stored in a snapshot, without any retired key (they were
     * removed from stored snapshots; this keeps any copy from being shown).
     *
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    public static function withoutRetired(?array $details): ?array
    {
        return $details === null ? null : array_diff_key($details, array_flip(self::RETIRED_KEYS));
    }

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
        ];
    }
}
