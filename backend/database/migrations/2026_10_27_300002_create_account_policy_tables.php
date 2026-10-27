<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account-level policies (privacy policy, terms of use) and their
 * acceptances, independent of any award cycle.
 *
 * policy_versions / application_consents stay reserved for cycle policies
 * accepted with a submitted application version. These tables hold what an
 * account accepts before any application exists: the exact published
 * version, the accepting account and the server time.
 *
 * At most one version of each policy is published at a time (generated
 * published_flag in a unique key). Published text is never edited; a new
 * version is published instead (App\Console\Commands\PublishAccountPolicy).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->unsignedInteger('version_number');
            $table->string('title_ar', 255);
            $table->mediumText('body_ar');
            $table->string('status', 16)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->unsignedTinyInteger('published_flag')->nullable()->virtualAs("CASE WHEN status = 'published' THEN 1 END");
            $table->datetimes();

            $table->unique(['code', 'version_number'], 'uq_account_policy_versions_version');
            $table->unique(['code', 'published_flag'], 'uq_account_policy_versions_published');
        });
        $this->addCheck('account_policy_versions', 'chk_account_policy_versions_code', "code IN ('privacy_policy', 'terms_of_use')");
        $this->addCheck('account_policy_versions', 'chk_account_policy_versions_status', "status IN ('draft', 'published', 'retired')");
        $this->addCheck('account_policy_versions', 'chk_account_policy_versions_number', 'version_number >= 1');
        $this->addCheck('account_policy_versions', 'chk_account_policy_versions_published', "status = 'draft' OR published_at IS NOT NULL");

        Schema::create('account_policy_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_account_policy_acceptances_user')->restrictOnDelete();
            $table->foreignId('policy_version_id')->constrained('account_policy_versions', 'id', 'fk_account_policy_acceptances_version')->restrictOnDelete();
            $table->string('context', 32);
            $table->dateTime('accepted_at');

            $table->unique(['user_id', 'policy_version_id'], 'uq_account_policy_acceptances_user_version');
        });
        $this->addCheck('account_policy_acceptances', 'chk_account_policy_acceptances_context', "context IN ('basic_profile')");
    }

    /**
     * Reverse the migrations.
     *
     * Refused, before anything is dropped, while any acceptance is recorded:
     * acceptances are evidence of what each account agreed to.
     */
    public function down(): void
    {
        if (Schema::hasTable('account_policy_acceptances')) {
            $accepted = DB::table('account_policy_acceptances')->count();

            if ($accepted > 0) {
                throw new RuntimeException("Cannot drop the account policy tables: {$accepted} acceptance(s) are recorded.");
            }
        }

        Schema::dropIfExists('account_policy_acceptances');
        Schema::dropIfExists('account_policy_versions');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
