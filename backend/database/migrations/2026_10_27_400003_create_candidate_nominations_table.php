<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The candidate journey's nomination in one cycle: which application the
 * candidate dashboard works on, and when Stage 2 («فئة الترشح وبيانات العمل»)
 * was confirmed.
 *
 * The row is written in the same transaction that creates the draft
 * application, so the journey always points at exactly the application it
 * created; no existing application is ever picked up or attached.
 * uq_candidate_nominations_journey (one row per candidate profile and cycle)
 * makes a confirmation idempotent. It describes this dashboard journey only:
 * the applications table itself gets no new limit, and the application-count
 * policy stays open (database-rules.md §4).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidate_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_candidate_nominations_user')->restrictOnDelete();
            $table->foreignId('candidate_profile_id')->constrained('candidate_profiles', 'id', 'fk_candidate_nominations_profile')->restrictOnDelete();
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_candidate_nominations_applicant')->restrictOnDelete();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('application_id');
            $table->dateTime('details_confirmed_at');
            $table->dateTime('created_at')->nullable();

            $table->foreign(['application_id', 'cycle_id'], 'fk_candidate_nominations_application')
                ->references(['id', 'cycle_id'])->on('applications')->restrictOnDelete();
            $table->unique(['candidate_profile_id', 'cycle_id'], 'uq_candidate_nominations_journey');
            $table->unique('application_id', 'uq_candidate_nominations_application');
            $table->index('cycle_id', 'idx_candidate_nominations_cycle');
        });
    }

    /**
     * Reverse the migrations.
     *
     * This migration rolls back first, so it refuses, before anything is
     * dropped, while any Stage 2 state exists that this or a later step of
     * the rollback (400002, 400001) would refuse to drop: confirmed
     * journeys, partnership rows, applications holding any Stage 2 value,
     * or configured subcategories.
     */
    public function down(): void
    {
        if (($state = $this->protectedStage2State()) !== []) {
            throw new RuntimeException('Cannot roll back the Stage 2 migrations: '.$this->describe($state).' exist. Nothing was changed.');
        }

        Schema::dropIfExists('candidate_nominations');
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
