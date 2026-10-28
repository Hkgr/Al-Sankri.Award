<?php

namespace App\Admin;

use App\Candidates\NominationCycles;
use App\Candidates\NominationTaxonomy;
use Illuminate\Support\Facades\DB;

/**
 * The award cycles the administration dashboard can select, with their
 * main domains and subcategories (the filters of «ملفات الترشح» and the
 * three committees of «لجان الحكام»).
 */
class AdminCycles
{
    public function __construct(private readonly NominationCycles $cycles) {}

    /**
     * Every cycle, newest year first, and the one selected by default: the
     * cycle open for nominations now if there is exactly one, otherwise the
     * newest that is not archived (null when there is none).
     *
     * @return array{cycles: list<array<string, mixed>>, default_cycle_id: int|null}
     */
    public function context(): array
    {
        $rows = DB::table('award_cycles')->orderByDesc('year')->orderByDesc('id')->get(['id', 'code', 'name_ar', 'year', 'status', 'timezone']);
        $domains = DB::table('cycle_domains')->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->whereIn('award_domains.code', NominationTaxonomy::domainCodes())
            ->get(['cycle_domains.id', 'cycle_domains.cycle_id', 'award_domains.code', 'cycle_domains.name_ar'])->groupBy('cycle_id');
        $subcategories = DB::table('cycle_domain_subcategories')->orderBy('display_order')->orderBy('id')
            ->get(['cycle_domain_id', 'code', 'name_ar'])->groupBy('cycle_domain_id');
        $order = array_flip(NominationTaxonomy::domainCodes());

        $cycles = $rows->map(fn (object $cycle): array => [
            'id' => (int) $cycle->id,
            'code' => $cycle->code,
            'name' => $cycle->name_ar,
            'year' => (int) $cycle->year,
            'status' => $cycle->status,
            'domains' => collect($domains->get($cycle->id, []))->sortBy(fn (object $domain): int => $order[$domain->code])->values()
                ->map(fn (object $domain): array => [
                    'code' => $domain->code,
                    'name' => $domain->name_ar,
                    'subcategories' => collect($subcategories->get($domain->id, []))
                        ->map(fn (object $subcategory): array => ['code' => $subcategory->code, 'name' => $subcategory->name_ar])->all(),
                ])->all(),
        ])->all();

        $open = $this->cycles->current()['cycle'];
        $fallback = $rows->firstWhere('status', '<>', 'archived');

        return ['cycles' => $cycles, 'default_cycle_id' => $open !== null ? (int) $open->id : ($fallback === null ? null : (int) $fallback->id)];
    }

    public function exists(int $cycleId): bool
    {
        return DB::table('award_cycles')->where('id', $cycleId)->exists();
    }
}
