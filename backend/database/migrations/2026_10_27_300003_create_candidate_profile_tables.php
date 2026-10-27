<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 1 of the candidate journey: the basic information of the applying
 * individual or institution.
 *
 * The shared applying-entity fields live in the existing applicants table
 * (and the institution's representative in applicant_members). These tables
 * add only what applicants lacks:
 *
 * - candidate_profiles: one confirmed basic profile per account, linking the
 *   account to its applicant, with the declarations and completion time;
 * - applicant_individual_details / applicant_institution_details: the
 *   fields of each type (the identity number is encrypted by the
 *   application, so its column holds ciphertext);
 * - applicant_social_links: up to five public links per applicant.
 *
 * One profile per account limits this onboarding journey only. It does not
 * limit award applications, which remain unconstrained (database-rules.md §4).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_candidate_profiles_user')->restrictOnDelete();
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_candidate_profiles_applicant')->restrictOnDelete();
            $table->string('profile_type', 16);
            $table->dateTime('data_accuracy_declared_at');
            $table->dateTime('representation_declared_at')->nullable();
            $table->dateTime('completed_at');
            $table->dateTime('created_at')->nullable();

            $table->unique('user_id', 'uq_candidate_profiles_user');
            $table->unique('applicant_id', 'uq_candidate_profiles_applicant');
        });
        $this->addCheck('candidate_profiles', 'chk_candidate_profiles_type', "profile_type IN ('individual', 'institution')");
        $this->addCheck('candidate_profiles', 'chk_candidate_profiles_representation', "(profile_type = 'institution') = (representation_declared_at IS NOT NULL)");

        Schema::create('applicant_individual_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_applicant_individual_details_applicant')->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('birth_date');
            $table->char('nationality_country_code', 2);
            $table->string('gender', 16);
            // Laravel "encrypted" cast: ciphertext only, never searchable.
            $table->text('national_id_number');
            $table->string('secondary_phone', 32)->nullable();
            $table->datetimes();

            $table->unique('applicant_id', 'uq_applicant_individual_details_applicant');
        });
        $this->addCheck('applicant_individual_details', 'chk_applicant_individual_details_nationality', $this->countryCheck('nationality_country_code'));
        $this->addCheck('applicant_individual_details', 'chk_applicant_individual_details_gender', "gender IN ('male', 'female')");

        Schema::create('applicant_institution_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_applicant_institution_details_applicant')->restrictOnDelete();
            $table->string('activity_type', 150);
            $table->string('website_url', 500)->nullable();
            $table->datetimes();

            $table->unique('applicant_id', 'uq_applicant_institution_details_applicant');
        });
        $this->addCheck('applicant_institution_details', 'chk_applicant_institution_details_website', $this->webUrlCheck('website_url', nullable: true));

        Schema::create('applicant_social_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants', 'id', 'fk_applicant_social_links_applicant')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('platform', 50);
            $table->string('url', 500);
            $table->datetimes();

            $table->unique(['applicant_id', 'position'], 'uq_applicant_social_links_position');
        });
        $this->addCheck('applicant_social_links', 'chk_applicant_social_links_position', 'position BETWEEN 1 AND 5');
        $this->addCheck('applicant_social_links', 'chk_applicant_social_links_url', $this->webUrlCheck('url'));
    }

    /**
     * Reverse the migrations.
     *
     * This migration rolls back first, so it refuses, before anything is
     * dropped, while any candidate data from this feature exists: confirmed
     * profiles, policy acceptances or candidate role grants. Otherwise the
     * later migrations of the batch would fail after these tables were gone.
     */
    public function down(): void
    {
        $profiles = Schema::hasTable('candidate_profiles') ? DB::table('candidate_profiles')->count() : 0;
        $acceptances = Schema::hasTable('account_policy_acceptances') ? DB::table('account_policy_acceptances')->count() : 0;
        $grants = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.code', 'candidate')
            ->count();

        if ($profiles + $acceptances + $grants > 0) {
            throw new RuntimeException(
                "Cannot roll back the candidate migrations: {$profiles} confirmed profile(s), {$acceptances} policy acceptance(s) "
                ."and {$grants} candidate role grant(s) exist. Nothing was changed."
            );
        }

        Schema::dropIfExists('applicant_social_links');
        Schema::dropIfExists('applicant_institution_details');
        Schema::dropIfExists('applicant_individual_details');
        Schema::dropIfExists('candidate_profiles');
    }

    /**
     * Uppercase ISO 3166-1 alpha-2, checked without regular expressions
     * (database-rules.md §2).
     */
    private function countryCheck(string $column): string
    {
        return "CHAR_LENGTH({$column}) = 2 AND ASCII(SUBSTRING({$column}, 1, 1)) BETWEEN 65 AND 90 AND ASCII(SUBSTRING({$column}, 2, 1)) BETWEEN 65 AND 90";
    }

    private function webUrlCheck(string $column, bool $nullable = false): string
    {
        $check = "({$column} LIKE 'https://%' OR {$column} LIKE 'http://%')";

        return $nullable ? "{$column} IS NULL OR {$check}" : $check;
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
