<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Committees (judging per cycle domain; integrity/appeals and trustees per
 * cycle), their members, versioned evaluation rubrics and criteria, judging
 * assignments, conflict-of-interest declarations, evaluations and scores.
 *
 * Composite foreign keys keep each assignment inside one cycle domain: its
 * application, committee, member and rubric must all agree. Scores can only
 * reference criteria of the evaluation's own rubric, and the referenced
 * criterion maximum is copied through the foreign key so a CHECK can reject
 * scores above it. The 3-or-5 judges rule is an activation-time application
 * rule, not a row-level constraint.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_committees_cycle')->restrictOnDelete();
            $table->unsignedBigInteger('cycle_domain_id')->nullable();
            $table->string('type', 16);
            $table->string('name_ar', 255);
            $table->string('status', 16)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'fk_committees_approved_by')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('scope_key')->virtualAs('COALESCE(cycle_domain_id, 0)');
            $table->unsignedTinyInteger('is_current')->nullable()->virtualAs("CASE WHEN status <> 'dissolved' THEN 1 END");
            $table->datetimes();

            $table->foreign(['cycle_domain_id', 'cycle_id'], 'fk_committees_cycle_domain')->references(['id', 'cycle_id'])->on('cycle_domains')->restrictOnDelete();
            $table->unique(['cycle_id', 'type', 'scope_key', 'is_current'], 'uq_committees_current');
            $table->unique(['id', 'cycle_id', 'type'], 'uq_committees_id_cycle_type');
            $table->unique(['id', 'cycle_domain_id'], 'uq_committees_id_domain');
        });
        $this->addCheck('committees', 'chk_committees_type', "type IN ('judging', 'integrity', 'trustees')");
        $this->addCheck('committees', 'chk_committees_domain', "(type = 'judging') = (cycle_domain_id IS NOT NULL)");
        $this->addCheck('committees', 'chk_committees_status', "status IN ('draft', 'approved', 'dissolved')");
        $this->addCheck('committees', 'chk_committees_approved', "status <> 'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)");

        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained('committees', 'id', 'fk_committee_members_committee')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_committee_members_user')->restrictOnDelete();
            $table->string('position', 16)->default('member');
            $table->string('specialty', 150)->nullable();
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->string('appointment_reference', 150)->nullable();
            $table->unsignedTinyInteger('is_current')->nullable()->virtualAs('CASE WHEN left_at IS NULL THEN 1 END');
            $table->datetimes();

            $table->unique(['committee_id', 'user_id', 'is_current'], 'uq_committee_members_current');
            $table->unique(['id', 'committee_id'], 'uq_committee_members_id_committee');
            $table->index(['user_id', 'left_at'], 'idx_committee_members_user');
        });
        $this->addCheck('committee_members', 'chk_committee_members_position', "position IN ('chair', 'member', 'secretary')");
        $this->addCheck('committee_members', 'chk_committee_members_period', 'left_at IS NULL OR left_at >= joined_at');

        Schema::create('evaluation_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_domain_id')->constrained('cycle_domains', 'id', 'fk_evaluation_rubrics_cycle_domain')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('name_ar', 255);
            $table->string('aggregation_method', 64)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'fk_evaluation_rubrics_approved_by')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->datetimes();

            $table->unique(['cycle_domain_id', 'version_number'], 'uq_evaluation_rubrics_version');
            $table->unique(['id', 'cycle_domain_id'], 'uq_evaluation_rubrics_id_domain');
        });
        $this->addCheck('evaluation_rubrics', 'chk_evaluation_rubrics_number', 'version_number >= 1');
        $this->addCheck('evaluation_rubrics', 'chk_evaluation_rubrics_status', "status IN ('draft', 'approved', 'retired')");
        $this->addCheck('evaluation_rubrics', 'chk_evaluation_rubrics_approved', "status <> 'approved' OR (aggregation_method IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL)");

        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('evaluation_rubrics', 'id', 'fk_evaluation_criteria_rubric')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name_ar', 255);
            $table->text('description')->nullable();
            $table->decimal('weight', 7, 4)->nullable();
            $table->decimal('max_score', 6, 2);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->datetimes();

            $table->unique(['rubric_id', 'code'], 'uq_evaluation_criteria_code');
            $table->unique(['id', 'rubric_id', 'max_score'], 'uq_evaluation_criteria_id_rubric_max');
        });
        $this->addCheck('evaluation_criteria', 'chk_evaluation_criteria_weight', 'weight IS NULL OR (weight >= 0 AND weight <= 100)');
        $this->addCheck('evaluation_criteria', 'chk_evaluation_criteria_max', 'max_score > 0');

        Schema::create('judging_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_version_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('cycle_domain_id');
            $table->unsignedBigInteger('committee_id');
            $table->unsignedBigInteger('committee_member_id');
            $table->unsignedBigInteger('rubric_id');
            $table->unsignedSmallInteger('round_number')->default(1);
            $table->foreignId('assigned_by')->constrained('users', 'id', 'fk_judging_assignments_assigned_by')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('due_at')->nullable();
            $table->string('status', 16)->default('assigned');
            $table->string('cancellation_reason', 1000)->nullable();
            $table->datetimes();

            $table->foreign(['application_version_id', 'application_id'], 'fk_judging_assignments_version')->references(['id', 'application_id'])->on('application_versions')->restrictOnDelete();
            $table->foreign(['application_id', 'cycle_domain_id'], 'fk_judging_assignments_application')->references(['id', 'cycle_domain_id'])->on('applications')->restrictOnDelete();
            $table->foreign(['committee_id', 'cycle_domain_id'], 'fk_judging_assignments_committee')->references(['id', 'cycle_domain_id'])->on('committees')->restrictOnDelete();
            $table->foreign(['committee_member_id', 'committee_id'], 'fk_judging_assignments_member')->references(['id', 'committee_id'])->on('committee_members')->restrictOnDelete();
            $table->foreign(['rubric_id', 'cycle_domain_id'], 'fk_judging_assignments_rubric')->references(['id', 'cycle_domain_id'])->on('evaluation_rubrics')->restrictOnDelete();
            $table->unique(['application_version_id', 'committee_member_id', 'round_number'], 'uq_judging_assignments_round');
            $table->unique(['id', 'application_id'], 'uq_judging_assignments_id_application');
            $table->unique(['id', 'rubric_id'], 'uq_judging_assignments_id_rubric');
            $table->index(['committee_member_id', 'status', 'due_at'], 'idx_judging_assignments_member');
            $table->index(['status', 'due_at'], 'idx_judging_assignments_due');
        });
        $this->addCheck('judging_assignments', 'chk_judging_assignments_round', 'round_number >= 1');
        $this->addCheck('judging_assignments', 'chk_judging_assignments_status', "status IN ('assigned', 'completed', 'recused', 'cancelled')");
        $this->addCheck('judging_assignments', 'chk_judging_assignments_cancelled', "status <> 'cancelled' OR cancellation_reason IS NOT NULL");
        $this->addCheck('judging_assignments', 'chk_judging_assignments_due', 'due_at IS NULL OR due_at >= assigned_at');

        Schema::create('conflict_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications', 'id', 'fk_conflict_declarations_application')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_conflict_declarations_user')->restrictOnDelete();
            $table->unsignedBigInteger('judging_assignment_id')->nullable();
            $table->string('declaration', 16);
            $table->text('relationship_description')->nullable();
            $table->dateTime('declared_at');
            $table->string('resolution', 16)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users', 'id', 'fk_conflict_declarations_resolved_by')->restrictOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->datetimes();

            $table->foreign(['judging_assignment_id', 'application_id'], 'fk_conflict_declarations_assignment')->references(['id', 'application_id'])->on('judging_assignments')->restrictOnDelete();
            $table->index(['application_id', 'user_id', 'declared_at'], 'idx_conflict_declarations_lookup');
            $table->index(['user_id', 'declared_at'], 'idx_conflict_declarations_user');
        });
        $this->addCheck('conflict_declarations', 'chk_conflict_declarations_value', "declaration IN ('no_conflict', 'conflict')");
        $this->addCheck('conflict_declarations', 'chk_conflict_declarations_detail', "declaration <> 'conflict' OR relationship_description IS NOT NULL");
        $this->addCheck('conflict_declarations', 'chk_conflict_declarations_resolution', "resolution IS NULL OR resolution IN ('recused', 'permitted', 'reassigned')");
        $this->addCheck('conflict_declarations', 'chk_conflict_declarations_resolved', '(resolution IS NULL) = (resolved_at IS NULL) AND (resolution IS NULL) = (resolved_by IS NULL) AND (resolution IS NULL) = (resolution_reason IS NULL)');
        $this->addCheck('conflict_declarations', 'chk_conflict_declarations_order', 'resolved_at IS NULL OR resolved_at >= declared_at');

        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('judging_assignment_id');
            $table->unsignedBigInteger('rubric_id');
            $table->string('status', 16)->default('draft');
            $table->text('overall_comment')->nullable();
            $table->string('recommendation', 16)->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users', 'id', 'fk_evaluations_reopened_by')->restrictOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->datetimes();

            $table->foreign(['judging_assignment_id', 'rubric_id'], 'fk_evaluations_assignment')->references(['id', 'rubric_id'])->on('judging_assignments')->restrictOnDelete();
            $table->unique('judging_assignment_id', 'uq_evaluations_assignment');
            $table->unique(['id', 'rubric_id'], 'uq_evaluations_id_rubric');
            $table->index('status', 'idx_evaluations_status');
        });
        $this->addCheck('evaluations', 'chk_evaluations_status', "status IN ('draft', 'finalized')");
        $this->addCheck('evaluations', 'chk_evaluations_finalized', "(status = 'finalized') = (finalized_at IS NOT NULL)");
        $this->addCheck('evaluations', 'chk_evaluations_recommendation', "recommendation IS NULL OR recommendation IN ('recommend', 'not_recommend', 'abstain')");
        $this->addCheck('evaluations', 'chk_evaluations_reopened', '(reopened_at IS NULL) = (reopened_by IS NULL) AND (reopened_at IS NULL) = (reopen_reason IS NULL)');

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluation_id');
            $table->unsignedBigInteger('rubric_id');
            $table->unsignedBigInteger('criterion_id');
            $table->decimal('criterion_max_score', 6, 2);
            $table->decimal('score', 6, 2)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_not_applicable')->default(false);
            $table->text('not_applicable_reason')->nullable();
            $table->datetimes();

            $table->foreign(['evaluation_id', 'rubric_id'], 'fk_evaluation_scores_evaluation')->references(['id', 'rubric_id'])->on('evaluations')->restrictOnDelete();
            $table->foreign(['criterion_id', 'rubric_id', 'criterion_max_score'], 'fk_evaluation_scores_criterion')->references(['id', 'rubric_id', 'max_score'])->on('evaluation_criteria')->restrictOnDelete();
            $table->unique(['evaluation_id', 'criterion_id'], 'uq_evaluation_scores_criterion');
        });
        $this->addCheck('evaluation_scores', 'chk_evaluation_scores_range', 'score IS NULL OR (score >= 0 AND score <= criterion_max_score)');
        $this->addCheck('evaluation_scores', 'chk_evaluation_scores_na', '(is_not_applicable = 0 AND not_applicable_reason IS NULL) OR (is_not_applicable = 1 AND score IS NULL AND not_applicable_reason IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('conflict_declarations');
        Schema::dropIfExists('judging_assignments');
        Schema::dropIfExists('evaluation_criteria');
        Schema::dropIfExists('evaluation_rubrics');
        Schema::dropIfExists('committee_members');
        Schema::dropIfExists('committees');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
