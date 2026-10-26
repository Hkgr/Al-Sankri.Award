<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public impact stories, kept apart from private application data, and the
 * append-only audit log.
 *
 * A story's publication consent must belong to the same application version
 * (composite foreign key). Public media is attached through file_links.
 * Audit rows reference their subject by type and id without a foreign key,
 * so deleting a subject can never cascade into its audit history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('published_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_version_id')->constrained('application_versions', 'id', 'fk_published_stories_version')->restrictOnDelete();
            $table->unsignedBigInteger('publication_consent_id');
            $table->string('public_slug', 160)->unique('uq_published_stories_slug');
            $table->string('public_title', 255);
            $table->string('public_applicant_name', 255);
            $table->text('public_summary')->nullable();
            $table->mediumText('public_story')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users', 'id', 'fk_published_stories_approved_by')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->datetimes();

            $table->foreign(['publication_consent_id', 'application_version_id'], 'fk_published_stories_consent')->references(['id', 'application_version_id'])->on('application_consents')->restrictOnDelete();
            $table->index(['status', 'published_at'], 'idx_published_stories_status');
        });
        $this->addCheck('published_stories', 'chk_published_stories_status', "status IN ('draft', 'approved', 'published', 'unpublished')");
        $this->addCheck('published_stories', 'chk_published_stories_approved', "status NOT IN ('approved', 'published') OR approved_by IS NOT NULL");
        $this->addCheck('published_stories', 'chk_published_stories_published', "status <> 'published' OR (published_at IS NOT NULL AND public_summary IS NOT NULL AND public_story IS NOT NULL)");

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id', 'fk_audit_logs_actor')->restrictOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('award_cycles', 'id', 'fk_audit_logs_cycle')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->dateTime('occurred_at', 6);

            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'idx_audit_logs_subject');
            $table->index('occurred_at', 'idx_audit_logs_occurred');
            $table->index(['actor_user_id', 'occurred_at'], 'idx_audit_logs_actor');
            $table->index(['cycle_id', 'occurred_at'], 'idx_audit_logs_cycle');
            $table->index('request_id', 'idx_audit_logs_request');
        });
        $this->addCheck('audit_logs', 'chk_audit_logs_subject', '(subject_type IS NULL) = (subject_id IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('published_stories');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
