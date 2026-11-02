<?php

use App\Candidates\AwardTerms;
use App\Candidates\NominationTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('applications', 'taxonomy_review_required')) {
            Schema::table('applications', fn (Blueprint $table) => $table->boolean('taxonomy_review_required')->default(false)->index());
        }
        if (! Schema::hasColumn('policy_versions', 'body_en')) {
            Schema::table('policy_versions', function (Blueprint $table) {
                $table->mediumText('body_en')->nullable();
                $table->char('content_hash', 64)->nullable();
            });
        }
        DB::transaction(function (): void {
            DB::table('award_cycles')->whereIn('award_name_ar', [
                'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية المجتمعية',
                'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والتنمية',
            ])->update(['award_name_ar' => 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع']);
            foreach (NominationTaxonomy::DOMAINS as $code => $definition) {
                DB::table('award_domains')->where('code', $code)->update(['name_ar' => $definition['name']]);
            }
            $renames = ['culture' => 'poetry', 'arts' => 'story'];
            $domains = DB::table('cycle_domains')->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
                ->whereIn('award_domains.code', NominationTaxonomy::domainCodes())
                ->get(['cycle_domains.id', 'cycle_domains.cycle_id', 'award_domains.code']);
            foreach ($domains as $domain) {
                $approved = NominationTaxonomy::DOMAINS[$domain->code];
                $legacy = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $domain->id)->whereIn('code', ['culture', 'arts', 'quality'])->pluck('id');
                DB::table('applications')->whereIn('subcategory_id', $legacy)->update(['taxonomy_review_required' => true]);
                DB::table('cycle_domains')->where('id', $domain->id)->update(['name_ar' => $approved['name']]);
                foreach ($renames as $old => $new) {
                    if (! isset($approved['subcategories'][$new])) {
                        continue;
                    }
                    $existing = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $domain->id)->where('code', $old)->first();
                    if ($existing === null) {
                        continue;
                    }
                    $duplicate = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $domain->id)->where('code', $new)->first();
                    if ($duplicate !== null) {
                        DB::table('applications')->where('subcategory_id', $duplicate->id)->where('cycle_domain_id', $domain->id)->update(['subcategory_id' => $existing->id]);
                        DB::table('cycle_domain_subcategories')->where('id', $duplicate->id)->delete();
                    }
                    DB::table('cycle_domain_subcategories')->where('id', $existing->id)->update(['code' => $new]);
                }
                DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $domain->id)->whereNotIn('code', array_keys($approved['subcategories']))->update(['is_active' => false]);
                foreach ($approved['subcategories'] as $order => $label) {
                    $row = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $domain->id)->where('code', $order)->first();
                    $values = ['name_ar' => $label, 'is_active' => true, 'display_order' => array_search($order, array_keys($approved['subcategories']), true) + 1, 'updated_at' => now()];
                    if ($row !== null) {
                        DB::table('cycle_domain_subcategories')->where('id', $row->id)->update($values);
                    } else {
                        DB::table('cycle_domain_subcategories')->insert($values + ['cycle_id' => $domain->cycle_id, 'cycle_domain_id' => $domain->id, 'code' => $order, 'created_at' => now()]);
                    }
                }
            }
            foreach (DB::table('award_cycles')->whereIn('status', ['draft', 'published'])->pluck('id') as $cycleId) {
                app(AwardTerms::class)->publishForCycle((int) $cycleId);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('award_cycles')->exists()) {
            throw new RuntimeException('The taxonomy and published terms have been updated. Restore a verified backup to reverse this data migration.');
        }
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('taxonomy_review_required'));
        Schema::table('policy_versions', fn (Blueprint $table) => $table->dropColumn(['body_en', 'content_hash']));
    }
};
