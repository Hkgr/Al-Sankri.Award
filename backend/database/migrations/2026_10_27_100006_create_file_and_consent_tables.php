<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored files, their links to exactly one target, versioned consent
 * policies and the consents accepted with each submitted version.
 *
 * file_links.appeal_id and file_links.published_story_id receive their
 * foreign keys in add_deferred_platform_foreign_keys, after those tables exist.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users', 'id', 'fk_files_uploaded_by')->restrictOnDelete();
            $table->string('storage_disk', 32);
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('detected_mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 16)->default('pending');
            $table->dateTime('scanned_at')->nullable();
            $table->string('access_level', 16)->default('private');
            $table->foreignId('source_file_id')->nullable()->constrained('files', 'id', 'fk_files_source')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['storage_disk', 'storage_path'], 'uq_files_location');
            $table->index(['uploaded_by', 'created_at'], 'idx_files_owner');
            $table->index(['scan_status', 'created_at'], 'idx_files_scan');
            $table->index('sha256', 'idx_files_sha256');
        });
        $this->addCheck('files', 'chk_files_scan_status', "scan_status IN ('pending', 'clean', 'infected', 'error')");
        $this->addCheck('files', 'chk_files_scanned_at', "(scan_status = 'pending') = (scanned_at IS NULL)");
        $this->addCheck('files', 'chk_files_access_level', "access_level IN ('private', 'public')");
        $this->addCheck('files', 'chk_files_sha256', "REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c')");
        $this->addCheck('files', 'chk_files_path', "storage_path NOT LIKE '%://%' AND storage_path NOT LIKE '%?%'");

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_policy_versions_cycle')->restrictOnDelete();
            $table->string('code', 64);
            $table->unsignedInteger('version_number');
            $table->string('title_ar', 255);
            $table->mediumText('body_ar');
            $table->string('status', 16)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->datetimes();

            $table->unique(['cycle_id', 'code', 'version_number'], 'uq_policy_versions_version');
        });
        $this->addCheck('policy_versions', 'chk_policy_versions_number', 'version_number >= 1');
        $this->addCheck('policy_versions', 'chk_policy_versions_status', "status IN ('draft', 'published', 'retired')");
        $this->addCheck('policy_versions', 'chk_policy_versions_published', "status = 'draft' OR published_at IS NOT NULL");

        Schema::create('application_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_version_id')->constrained('application_versions', 'id', 'fk_application_consents_version')->restrictOnDelete();
            $table->foreignId('policy_version_id')->constrained('policy_versions', 'id', 'fk_application_consents_policy')->restrictOnDelete();
            $table->foreignId('accepted_by')->constrained('users', 'id', 'fk_application_consents_accepted_by')->restrictOnDelete();
            $table->dateTime('accepted_at');
            $table->string('representative_capacity', 150)->nullable();

            $table->unique(['application_version_id', 'policy_version_id'], 'uq_application_consents_policy');
            $table->unique(['id', 'application_version_id'], 'uq_application_consents_id_version');
        });

        Schema::create('file_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('files', 'id', 'fk_file_links_file')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications', 'id', 'fk_file_links_application')->restrictOnDelete();
            $table->foreignId('application_version_id')->nullable()->constrained('application_versions', 'id', 'fk_file_links_version')->restrictOnDelete();
            $table->unsignedBigInteger('appeal_id')->nullable();
            $table->unsignedBigInteger('published_story_id')->nullable();
            $table->string('purpose', 64);
            $table->string('caption', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->datetimes();

            $table->unique(['file_id', 'application_id'], 'uq_file_links_application');
            $table->unique(['file_id', 'application_version_id'], 'uq_file_links_version');
            $table->unique(['file_id', 'appeal_id'], 'uq_file_links_appeal');
            $table->unique(['file_id', 'published_story_id'], 'uq_file_links_story');
            $table->index(['application_id', 'purpose'], 'idx_file_links_application');
            $table->index(['application_version_id', 'purpose'], 'idx_file_links_version');
            $table->index('appeal_id', 'idx_file_links_appeal');
            $table->index('published_story_id', 'idx_file_links_story');
        });
        $this->addCheck('file_links', 'chk_file_links_one_target', '(application_id IS NOT NULL) + (application_version_id IS NOT NULL) + (appeal_id IS NOT NULL) + (published_story_id IS NOT NULL) = 1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_links');
        Schema::dropIfExists('application_consents');
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('files');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
