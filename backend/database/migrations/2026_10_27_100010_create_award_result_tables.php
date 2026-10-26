<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned award-slot results recommended by the domain's judging committee,
 * and the trustees' procedural ratification records.
 *
 * One current result per slot: is_current is 1 unless the result is
 * superseded or withdrawn, and (award_slot_id, is_current) is unique.
 * Superseding rows must reference an earlier result of the same slot, and a
 * result can be superseded once. A grant names an application version of the
 * slot's own domain; a withholding names none. Ratification never stores or
 * replaces scores or technical reasoning.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('award_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_award_results_cycle')->restrictOnDelete();
            $table->unsignedBigInteger('cycle_domain_id');
            $table->unsignedBigInteger('award_slot_id');
            $table->unsignedBigInteger('application_id')->nullable();
            $table->unsignedBigInteger('application_version_id')->nullable();
            $table->unsignedInteger('decision_version');
            $table->string('decision_type', 16);
            $table->unsignedBigInteger('recommending_committee_id');
            $table->text('technical_reason');
            $table->json('score_summary')->nullable();
            $table->string('status', 16)->default('recommended');
            $table->unsignedBigInteger('supersedes_result_id')->nullable();
            $table->dateTime('recommended_at');
            $table->dateTime('published_at')->nullable();
            $table->unsignedTinyInteger('is_current')->nullable()->virtualAs("CASE WHEN status NOT IN ('superseded', 'withdrawn') THEN 1 END");
            $table->datetimes();

            $table->foreign(['cycle_domain_id', 'cycle_id'], 'fk_award_results_cycle_domain')->references(['id', 'cycle_id'])->on('cycle_domains')->restrictOnDelete();
            $table->foreign(['award_slot_id', 'cycle_domain_id'], 'fk_award_results_slot')->references(['id', 'cycle_domain_id'])->on('award_slots')->restrictOnDelete();
            $table->foreign(['application_id', 'cycle_domain_id'], 'fk_award_results_application')->references(['id', 'cycle_domain_id'])->on('applications')->restrictOnDelete();
            $table->foreign(['application_version_id', 'application_id'], 'fk_award_results_version')->references(['id', 'application_id'])->on('application_versions')->restrictOnDelete();
            $table->foreign(['recommending_committee_id', 'cycle_domain_id'], 'fk_award_results_committee')->references(['id', 'cycle_domain_id'])->on('committees')->restrictOnDelete();
            $table->unique(['award_slot_id', 'decision_version'], 'uq_award_results_version');
            $table->unique(['award_slot_id', 'is_current'], 'uq_award_results_current');
            $table->unique('supersedes_result_id', 'uq_award_results_supersedes');
            $table->unique(['id', 'award_slot_id'], 'uq_award_results_id_slot');
            $table->unique(['id', 'cycle_id'], 'uq_award_results_id_cycle');
            $table->index(['award_slot_id', 'status'], 'idx_award_results_slot_status');
            $table->index(['status', 'published_at'], 'idx_award_results_published');
        });
        Schema::table('award_results', function (Blueprint $table) {
            $table->foreign(['supersedes_result_id', 'award_slot_id'], 'fk_award_results_supersedes')->references(['id', 'award_slot_id'])->on('award_results')->restrictOnDelete();
        });
        $this->addCheck('award_results', 'chk_award_results_version', 'decision_version >= 1');
        $this->addCheck('award_results', 'chk_award_results_type', "decision_type IN ('grant', 'withhold')");
        $this->addCheck('award_results', 'chk_award_results_target', "(decision_type = 'grant' AND application_id IS NOT NULL AND application_version_id IS NOT NULL) OR (decision_type = 'withhold' AND application_id IS NULL AND application_version_id IS NULL)");
        $this->addCheck('award_results', 'chk_award_results_status', "status IN ('recommended', 'ratified', 'returned', 'published', 'superseded', 'withdrawn')");
        $this->addCheck('award_results', 'chk_award_results_published', "status <> 'published' OR published_at IS NOT NULL");
        $this->addCheck('award_results', 'chk_award_results_supersedes', '(decision_version = 1) = (supersedes_result_id IS NULL)');

        Schema::create('result_ratifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('result_id');
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_result_ratifications_cycle')->restrictOnDelete();
            $table->unsignedBigInteger('trustees_committee_id');
            $table->string('committee_type', 16)->storedAs("'trustees'");
            $table->foreignId('recorded_by')->constrained('users', 'id', 'fk_result_ratifications_recorded_by')->restrictOnDelete();
            $table->string('decision', 32);
            $table->json('procedural_checklist')->nullable();
            $table->text('reason')->nullable();
            $table->string('meeting_reference', 150)->nullable();
            $table->dateTime('decided_at');

            $table->foreign(['result_id', 'cycle_id'], 'fk_result_ratifications_result')->references(['id', 'cycle_id'])->on('award_results')->restrictOnDelete();
            $table->foreign(['trustees_committee_id', 'cycle_id', 'committee_type'], 'fk_result_ratifications_committee')->references(['id', 'cycle_id', 'type'])->on('committees')->restrictOnDelete();
            $table->index(['result_id', 'decided_at'], 'idx_result_ratifications_result');
        });
        $this->addCheck('result_ratifications', 'chk_result_ratifications_decision', "decision IN ('ratified', 'returned_for_correction')");
        $this->addCheck('result_ratifications', 'chk_result_ratifications_reason', "decision <> 'returned_for_correction' OR reason IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('result_ratifications');
        Schema::dropIfExists('award_results');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
