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
     * dropped, while any Stage 2 data exists: confirmed journeys,
     * partnership rows or applications holding Stage 2 values.
     */
    public function down(): void
    {
        $journeys = Schema::hasTable('candidate_nominations') ? DB::table('candidate_nominations')->count() : 0;
        $partnerships = Schema::hasTable('application_partnerships') ? DB::table('application_partnerships')->count() : 0;
        $applications = Schema::hasColumn('applications', 'funding_type')
            ? DB::table('applications')->where(fn ($query) => $query->whereNotNull('subcategory_id')->orWhereNotNull('funding_type')->orWhereNotNull('purpose_and_mission'))->count()
            : 0;

        if ($journeys + $partnerships + $applications > 0) {
            throw new RuntimeException(
                "Cannot roll back the Stage 2 migrations: {$journeys} confirmed journey(s), {$applications} application(s) with Stage 2 data "
                ."and {$partnerships} partnership row(s) exist. Nothing was changed."
            );
        }

        Schema::dropIfExists('candidate_nominations');
    }
};
