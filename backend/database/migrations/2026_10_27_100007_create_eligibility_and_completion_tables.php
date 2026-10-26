<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eligibility reviews of submitted versions (repeatable; earlier reviews
 * are kept) and completion requests with their source and response versions.
 *
 * Composite foreign keys on (version id, application_id) guarantee that both
 * versions of a completion request belong to the request's own application.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('eligibility_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_version_id')->constrained('application_versions', 'id', 'fk_eligibility_reviews_version')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users', 'id', 'fk_eligibility_reviews_reviewer')->restrictOnDelete();
            $table->string('checklist_version', 32);
            $table->json('checklist_answers')->nullable();
            $table->string('decision', 16)->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->datetimes();

            $table->index(['application_version_id', 'reviewed_at'], 'idx_eligibility_reviews_version');
            $table->index(['reviewer_user_id', 'reviewed_at'], 'idx_eligibility_reviews_reviewer');
        });
        $this->addCheck('eligibility_reviews', 'chk_eligibility_reviews_decision', "decision IS NULL OR decision IN ('eligible', 'ineligible', 'incomplete')");
        $this->addCheck('eligibility_reviews', 'chk_eligibility_reviews_reviewed', '(decision IS NULL) = (reviewed_at IS NULL)');
        $this->addCheck('eligibility_reviews', 'chk_eligibility_reviews_reason', "decision IS NULL OR decision = 'eligible' OR reason IS NOT NULL");

        Schema::create('completion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications', 'id', 'fk_completion_requests_application')->restrictOnDelete();
            $table->unsignedBigInteger('based_on_version_id');
            $table->foreignId('requested_by')->constrained('users', 'id', 'fk_completion_requests_requested_by')->restrictOnDelete();
            $table->text('message');
            $table->json('requested_items')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('response_version_id')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->datetimes();

            $table->foreign(['based_on_version_id', 'application_id'], 'fk_completion_requests_based_on')->references(['id', 'application_id'])->on('application_versions')->restrictOnDelete();
            $table->foreign(['response_version_id', 'application_id'], 'fk_completion_requests_response')->references(['id', 'application_id'])->on('application_versions')->restrictOnDelete();
            $table->unique(['id', 'application_id'], 'uq_completion_requests_id_application');
            $table->index(['application_id', 'status'], 'idx_completion_requests_application');
            $table->index(['status', 'due_at'], 'idx_completion_requests_due');
        });
        $this->addCheck('completion_requests', 'chk_completion_requests_status', "status IN ('open', 'answered', 'expired', 'cancelled')");
        $this->addCheck('completion_requests', 'chk_completion_requests_closed', "(status = 'open') = (closed_at IS NULL)");
        $this->addCheck('completion_requests', 'chk_completion_requests_response', "(status = 'answered') = (response_version_id IS NOT NULL)");
        $this->addCheck('completion_requests', 'chk_completion_requests_distinct', 'response_version_id IS NULL OR response_version_id <> based_on_version_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('completion_requests');
        Schema::dropIfExists('eligibility_reviews');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
