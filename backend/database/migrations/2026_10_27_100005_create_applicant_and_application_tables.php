<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applicant entities, their members, applications (drafts may be
 * incomplete), implementation locations and submitted versions.
 *
 * Circular keys are added later in add_deferred_platform_foreign_keys:
 * applications.current_version_id and application_versions.completion_request_id.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name_ar', 255);
            $table->foreignId('representative_user_id')->constrained('users', 'id', 'fk_applicants_representative')->restrictOnDelete();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->char('headquarters_country_code', 2)->nullable();
            $table->string('headquarters_governorate', 100)->nullable();
            $table->string('headquarters_address', 500)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('registration_authority', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->datetimes();

            $table->index(['type', 'status'], 'idx_applicants_type_status');
        });
        $this->addCheck('applicants', 'chk_applicants_type', "type IN ('individual', 'team', 'initiative', 'official_institution', 'organization')");
        $this->addCheck('applicants', 'chk_applicants_status', "status IN ('active', 'suspended', 'archived')");
        $this->addCheck('applicants', 'chk_applicants_country', "headquarters_country_code IS NULL OR REGEXP_LIKE(headquarters_country_code, '^[A-Z]{2}$', 'c')");

        Schema::create('applicant_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_applicant_members_applicant')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'fk_applicant_members_user')->nullOnDelete();
            $table->string('full_name', 255);
            $table->string('role_title', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_primary_contact')->default(false);
            $table->unsignedTinyInteger('primary_flag')->nullable()->virtualAs('CASE WHEN is_primary_contact THEN 1 END');
            $table->datetimes();

            $table->unique(['applicant_id', 'primary_flag'], 'uq_applicant_members_primary');
            $table->unique(['applicant_id', 'user_id'], 'uq_applicant_members_user');
            $table->index('user_id', 'idx_applicant_members_user');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_applications_cycle')->restrictOnDelete();
            $table->unsignedBigInteger('cycle_domain_id');
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_applications_applicant')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users', 'id', 'fk_applications_created_by')->restrictOnDelete();
            $table->string('reference_number', 32)->nullable()->unique('uq_applications_reference');
            $table->string('title', 255)->nullable();
            $table->text('summary')->nullable();
            $table->mediumText('description')->nullable();
            $table->text('problem_statement')->nullable();
            $table->date('implementation_started_on')->nullable();
            $table->date('implementation_ended_on')->nullable();
            $table->string('implementation_status', 16)->nullable();
            $table->text('target_groups')->nullable();
            $table->mediumText('documented_impact')->nullable();
            $table->text('sustainability_plan')->nullable();
            $table->text('governance_description')->nullable();
            $table->json('supplementary_answers')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->dateTime('first_submitted_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 1000)->nullable();
            $table->datetimes();

            $table->foreign(['cycle_domain_id', 'cycle_id'], 'fk_applications_cycle_domain')->references(['id', 'cycle_id'])->on('cycle_domains')->restrictOnDelete();
            $table->unique(['id', 'cycle_id'], 'uq_applications_id_cycle');
            $table->unique(['id', 'cycle_domain_id'], 'uq_applications_id_domain');
            $table->index(['cycle_domain_id', 'status', 'first_submitted_at'], 'idx_applications_domain_status');
            $table->index(['status', 'first_submitted_at'], 'idx_applications_status_submitted');
            $table->index(['applicant_id', 'cycle_id'], 'idx_applications_applicant_cycle');
        });
        $this->addCheck('applications', 'chk_applications_status', "status IN ('draft', 'submitted', 'completion_requested', 'eligible', 'ineligible', 'in_judging', 'withdrawn', 'closed')");
        $this->addCheck('applications', 'chk_applications_impl_status', "implementation_status IS NULL OR implementation_status IN ('ongoing', 'completed', 'suspended')");
        $this->addCheck('applications', 'chk_applications_impl_dates', 'implementation_started_on IS NULL OR implementation_ended_on IS NULL OR implementation_ended_on >= implementation_started_on');
        $this->addCheck('applications', 'chk_applications_submitted', "status = 'draft' OR (first_submitted_at IS NOT NULL AND current_version_id IS NOT NULL)");
        $this->addCheck('applications', 'chk_applications_withdrawn', "(status = 'withdrawn') = (withdrawn_at IS NOT NULL)");

        Schema::create('application_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications', 'id', 'fk_application_locations_application')->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->string('governorate', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('locality', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedTinyInteger('primary_flag')->nullable()->virtualAs('CASE WHEN is_primary THEN 1 END');
            $table->datetimes();

            $table->unique(['application_id', 'primary_flag'], 'uq_application_locations_primary');
            $table->index(['country_code', 'governorate'], 'idx_application_locations_area');
        });
        $this->addCheck('application_locations', 'chk_application_locations_country', "REGEXP_LIKE(country_code, '^[A-Z]{2}$', 'c')");
        $this->addCheck('application_locations', 'chk_application_locations_coords', '(latitude IS NULL) = (longitude IS NULL)');
        $this->addCheck('application_locations', 'chk_application_locations_lat', 'latitude IS NULL OR latitude BETWEEN -90 AND 90');
        $this->addCheck('application_locations', 'chk_application_locations_lng', 'longitude IS NULL OR longitude BETWEEN -180 AND 180');

        Schema::create('application_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications', 'id', 'fk_application_versions_application')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('submission_type', 32);
            $table->unsignedBigInteger('completion_request_id')->nullable();
            $table->foreignId('submitted_by')->constrained('users', 'id', 'fk_application_versions_submitted_by')->restrictOnDelete();
            $table->dateTime('submitted_at');
            $table->json('data_snapshot');
            $table->json('form_snapshot');
            $table->char('content_hash', 64);
            $table->dateTime('created_at')->nullable();

            $table->unique(['application_id', 'version_number'], 'uq_application_versions_number');
            $table->unique(['id', 'application_id'], 'uq_application_versions_id_application');
            $table->index('submitted_at', 'idx_application_versions_submitted');
        });
        $this->addCheck('application_versions', 'chk_application_versions_number', 'version_number >= 1');
        $this->addCheck('application_versions', 'chk_application_versions_type', "submission_type IN ('initial', 'completion_response')");
        $this->addCheck('application_versions', 'chk_application_versions_first', "version_number <> 1 OR submission_type = 'initial'");
        $this->addCheck('application_versions', 'chk_application_versions_completion', "(submission_type = 'completion_response') = (completion_request_id IS NOT NULL)");
        $this->addCheck('application_versions', 'chk_application_versions_hash', "REGEXP_LIKE(content_hash, '^[0-9a-f]{64}$', 'c')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_versions');
        Schema::dropIfExists('application_locations');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('applicant_members');
        Schema::dropIfExists('applicants');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
