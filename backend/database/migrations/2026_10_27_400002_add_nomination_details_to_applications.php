<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of the candidate journey («فئة الترشح وبيانات العمل») on the
 * existing applications table, plus its partnership rows.
 *
 * Existing columns keep their meaning: title, documented_impact,
 * implementation_started_on / _ended_on / _status, and application_locations
 * for the places of implementation. New columns cover what had no accurate
 * home:
 *
 * - subcategory_id: (subcategory_id, cycle_domain_id) must name a
 *   subcategory of the application's own domain, which fk_applications_cycle_domain
 *   already ties to the application's cycle;
 * - purpose_and_mission, objectives: plain text;
 * - funding_type, funder_name, funding_amount_usd: the funding declaration,
 *   with an exact DECIMAL amount in US dollars. chk_applications_funding
 *   allows exactly the fields each funding type needs, and nothing else;
 * - has_partnerships: the candidate's yes/no answer; the partners are rows
 *   of application_partnerships.
 */
return new class extends Migration
{
    private const NEW_COLUMNS = ['subcategory_id', 'purpose_and_mission', 'objectives', 'funding_type', 'funder_name', 'funding_amount_usd', 'has_partnerships'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('subcategory_id')->nullable()->after('cycle_domain_id');
            $table->text('purpose_and_mission')->nullable()->after('problem_statement');
            $table->text('objectives')->nullable()->after('purpose_and_mission');
            $table->string('funding_type', 32)->nullable()->after('governance_description');
            $table->string('funder_name', 255)->nullable()->after('funding_type');
            $table->decimal('funding_amount_usd', 14, 2)->nullable()->after('funder_name');
            $table->boolean('has_partnerships')->nullable()->after('funding_amount_usd');

            $table->index(['subcategory_id', 'cycle_domain_id'], 'idx_applications_subcategory');
            $table->foreign(['subcategory_id', 'cycle_domain_id'], 'fk_applications_subcategory')
                ->references(['id', 'cycle_domain_id'])->on('cycle_domain_subcategories')->restrictOnDelete();
        });

        $this->addCheck('applications', 'chk_applications_funding_type',
            "funding_type IS NULL OR funding_type IN ('fully_funded', 'partially_funded', 'self_funded', 'not_needed')");
        // COALESCE keeps every branch TRUE or FALSE: a NULL funding_type must
        // not make the expression UNKNOWN, which a CHECK would let through.
        $this->addCheck('applications', 'chk_applications_funding',
            '(funding_type IS NULL AND funder_name IS NULL AND funding_amount_usd IS NULL)'
            ." OR (COALESCE(funding_type, '') IN ('fully_funded', 'partially_funded') AND funder_name IS NOT NULL AND funding_amount_usd IS NOT NULL)"
            ." OR (COALESCE(funding_type, '') = 'self_funded' AND funder_name IS NULL AND funding_amount_usd IS NOT NULL)"
            ." OR (COALESCE(funding_type, '') = 'not_needed' AND funder_name IS NULL AND funding_amount_usd IS NULL)");
        $this->addCheck('applications', 'chk_applications_funding_amount', 'funding_amount_usd IS NULL OR funding_amount_usd > 0');
        $this->addCheck('applications', 'chk_applications_funder_name', "funder_name IS NULL OR funder_name <> ''");

        Schema::create('application_partnerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications', 'id', 'fk_application_partnerships_application')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('partner_name', 255);
            $table->string('contribution', 1000)->nullable();
            $table->datetimes();

            $table->unique(['application_id', 'position'], 'uq_application_partnerships_position');
        });
        $this->addCheck('application_partnerships', 'chk_application_partnerships_position', 'position BETWEEN 1 AND 10');
        $this->addCheck('application_partnerships', 'chk_application_partnerships_name', "partner_name <> ''");
    }

    /**
     * Reverse the migrations.
     *
     * Refused, before anything changes, while any application holds Stage 2
     * data in the new columns, any partnership row exists, or (because
     * 400001 rolls back next and would refuse them, leaving this step done
     * and that one not) any subcategory is configured.
     */
    public function down(): void
    {
        if (($state = $this->protectedStage2State()) !== []) {
            throw new RuntimeException('Cannot roll back the Stage 2 application columns: '.$this->describe($state).' exist. Nothing was changed.');
        }

        Schema::dropIfExists('application_partnerships');

        foreach (['chk_applications_funder_name', 'chk_applications_funding_amount', 'chk_applications_funding', 'chk_applications_funding_type'] as $check) {
            $this->dropCheck('applications', $check);
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign('fk_applications_subcategory');
            $table->dropIndex('idx_applications_subcategory');
            $table->dropColumn(self::NEW_COLUMNS);
        });
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    private function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        }
    }

    /**
     * Every kind of Stage 2 state that one of the Stage 2 migrations refuses
     * to drop, counted before anything changes: a batch rollback refuses in
     * its first migration rather than half-way through (400003, 400002 and
     * 400001 roll back in that order).
     *
     * @return array<string, int>
     */
    private function protectedStage2State(): array
    {
        $count = fn (string $table): int => Schema::hasTable($table) ? DB::table($table)->count() : 0;
        $columns = array_values(array_filter(
            ['subcategory_id', 'purpose_and_mission', 'objectives', 'funding_type', 'funder_name', 'funding_amount_usd', 'has_partnerships'],
            fn (string $column): bool => Schema::hasColumn('applications', $column),
        ));

        return array_filter([
            'confirmed journey(s)' => $count('candidate_nominations'),
            'partnership row(s)' => $count('application_partnerships'),
            'application(s) holding Stage 2 values' => $columns === [] ? 0 : DB::table('applications')
                ->where(function ($query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                })
                ->count(),
            'configured subcategory row(s)' => $count('cycle_domain_subcategories'),
        ]);
    }

    /**
     * @param  array<string, int>  $state
     */
    private function describe(array $state): string
    {
        return implode(', ', array_map(fn (string $kind, int $rows): string => "{$rows} {$kind}", array_keys($state), $state));
    }
};
