<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys that close circular relationships or point at tables created
 * after the referencing table. Running last means a rollback removes them
 * first, before the earlier migrations drop their tables.
 *
 * - applications.current_version_id must be a version of the same
 *   application: (current_version_id, id) -> application_versions (id, application_id).
 * - application_versions.completion_request_id must be a request of the same
 *   application: (completion_request_id, application_id) -> completion_requests (id, application_id).
 * - file_links.appeal_id and file_links.published_story_id.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->index(['current_version_id', 'id'], 'idx_applications_current_version');
            $table->foreign(['current_version_id', 'id'], 'fk_applications_current_version')->references(['id', 'application_id'])->on('application_versions')->restrictOnDelete();
        });

        Schema::table('application_versions', function (Blueprint $table) {
            $table->index(['completion_request_id', 'application_id'], 'idx_application_versions_completion');
            $table->foreign(['completion_request_id', 'application_id'], 'fk_application_versions_completion')->references(['id', 'application_id'])->on('completion_requests')->restrictOnDelete();
        });

        Schema::table('file_links', function (Blueprint $table) {
            $table->foreign('appeal_id', 'fk_file_links_appeal')->references('id')->on('appeals')->restrictOnDelete();
            $table->foreign('published_story_id', 'fk_file_links_story')->references('id')->on('published_stories')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('file_links', function (Blueprint $table) {
            $table->dropForeign('fk_file_links_story');
            $table->dropForeign('fk_file_links_appeal');
        });

        Schema::table('application_versions', function (Blueprint $table) {
            $table->dropForeign('fk_application_versions_completion');
            $table->dropIndex('idx_application_versions_completion');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign('fk_applications_current_version');
            $table->dropIndex('idx_applications_current_version');
        });
    }
};
