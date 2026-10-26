<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appeals and procedural complaints handled by the cycle's integrity/appeals
 * committee, and their decisions (kept as history, never overwritten).
 *
 * The optional application and committee must belong to the appeal's cycle
 * (composite foreign keys); the committee must be of type "integrity"
 * (generated committee_type column). challenged_record_type is a closed list
 * of short type codes, never a class name; challenged_record_id has no
 * foreign key, so its existence is an application-layer check.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_appeals_cycle')->restrictOnDelete();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->foreignId('submitted_by')->constrained('users', 'id', 'fk_appeals_submitted_by')->restrictOnDelete();
            $table->unsignedBigInteger('committee_id')->nullable();
            $table->string('committee_type', 16)->storedAs("'integrity'");
            $table->string('type', 32);
            $table->string('subject', 255);
            $table->text('description');
            $table->string('challenged_record_type', 32)->nullable();
            $table->unsignedBigInteger('challenged_record_id')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->dateTime('submitted_at');
            $table->datetimes();

            $table->foreign(['application_id', 'cycle_id'], 'fk_appeals_application')->references(['id', 'cycle_id'])->on('applications')->restrictOnDelete();
            $table->foreign(['committee_id', 'cycle_id', 'committee_type'], 'fk_appeals_committee')->references(['id', 'cycle_id', 'type'])->on('committees')->restrictOnDelete();
            $table->index(['cycle_id', 'status', 'submitted_at'], 'idx_appeals_cycle_status');
            $table->index(['challenged_record_type', 'challenged_record_id'], 'idx_appeals_challenged');
        });
        $this->addCheck('appeals', 'chk_appeals_type', "type IN ('eligibility_appeal', 'result_appeal', 'procedural_complaint', 'conflict_of_interest_report')");
        $this->addCheck('appeals', 'chk_appeals_status', "status IN ('submitted', 'under_review', 'decided', 'withdrawn')");
        $this->addCheck('appeals', 'chk_appeals_challenged_pair', '(challenged_record_type IS NULL) = (challenged_record_id IS NULL)');
        $this->addCheck('appeals', 'chk_appeals_challenged_type', "challenged_record_type IS NULL OR challenged_record_type IN ('application', 'eligibility_review', 'completion_request', 'conflict_declaration', 'evaluation', 'award_result', 'result_ratification')");

        Schema::create('appeal_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appeal_id')->constrained('appeals', 'id', 'fk_appeal_decisions_appeal')->restrictOnDelete();
            $table->string('decision', 32);
            $table->text('reason');
            $table->text('required_action')->nullable();
            $table->foreignId('issued_by')->constrained('users', 'id', 'fk_appeal_decisions_issued_by')->restrictOnDelete();
            $table->dateTime('issued_at');
            $table->dateTime('notified_at')->nullable();

            $table->index(['appeal_id', 'issued_at'], 'idx_appeal_decisions_appeal');
        });
        $this->addCheck('appeal_decisions', 'chk_appeal_decisions_value', "decision IN ('upheld', 'partially_upheld', 'rejected', 'inadmissible')");
        $this->addCheck('appeal_decisions', 'chk_appeal_decisions_notified', 'notified_at IS NULL OR notified_at >= issued_at');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appeal_decisions');
        Schema::dropIfExists('appeals');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
