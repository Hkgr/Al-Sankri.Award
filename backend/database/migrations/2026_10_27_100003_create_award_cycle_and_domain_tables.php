<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Award cycles, their overlapping stages, the domain catalogue, the
 * cycle-specific domain definitions and the award slots of each domain.
 *
 * No domains, slots or award counts are seeded or hardcoded here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('award_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique('uq_award_cycles_code');
            $table->string('name_ar', 255);
            $table->string('award_name_ar', 255);
            $table->unsignedSmallInteger('year');
            $table->string('timezone', 64);
            $table->string('status', 16)->default('draft');
            $table->json('eligibility_rules')->nullable();
            $table->json('submission_rules')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->datetimes();

            $table->index(['status', 'year'], 'idx_award_cycles_status_year');
        });
        $this->addCheck('award_cycles', 'chk_award_cycles_status', "status IN ('draft', 'published', 'closed', 'archived')");
        $this->addCheck('award_cycles', 'chk_award_cycles_published', "status NOT IN ('published', 'closed') OR published_at IS NOT NULL");
        $this->addCheck('award_cycles', 'chk_award_cycles_archived', "(status = 'archived') = (archived_at IS NOT NULL)");

        Schema::create('cycle_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_cycle_stages_cycle')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name_ar', 150);
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->string('status', 16)->default('planned');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->foreignId('enabled_by')->nullable()->constrained('users', 'id', 'fk_cycle_stages_enabled_by')->restrictOnDelete();
            $table->dateTime('enabled_at')->nullable();
            $table->string('last_change_reason', 1000)->nullable();
            $table->datetimes();

            $table->unique(['cycle_id', 'code'], 'uq_cycle_stages_code');
            $table->index(['cycle_id', 'status'], 'idx_cycle_stages_status');
        });
        $this->addCheck('cycle_stages', 'chk_cycle_stages_status', "status IN ('planned', 'enabled', 'closed', 'cancelled')");
        $this->addCheck('cycle_stages', 'chk_cycle_stages_window', 'opens_at IS NULL OR closes_at IS NULL OR closes_at > opens_at');
        $this->addCheck('cycle_stages', 'chk_cycle_stages_enabled', "status <> 'enabled' OR (enabled_at IS NOT NULL AND enabled_by IS NOT NULL)");

        Schema::create('award_domains', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique('uq_award_domains_code');
            $table->string('name_ar', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('cycle_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_cycle_domains_cycle')->restrictOnDelete();
            $table->foreignId('domain_id')->constrained('award_domains', 'id', 'fk_cycle_domains_domain')->restrictOnDelete();
            $table->string('name_ar', 150);
            $table->text('description')->nullable();
            $table->json('eligibility_requirements')->nullable();
            $table->json('supplementary_questions')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_open_for_applications')->default(false);
            $table->datetimes();

            $table->unique(['cycle_id', 'domain_id'], 'uq_cycle_domains_pair');
            // Parent key for composite foreign keys that must stay inside one cycle.
            $table->unique(['id', 'cycle_id'], 'uq_cycle_domains_id_cycle');
        });

        Schema::create('award_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_domain_id')->constrained('cycle_domains', 'id', 'fk_award_slots_cycle_domain')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name_ar', 150);
            $table->unsignedSmallInteger('slot_number');
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->datetimes();

            $table->unique(['cycle_domain_id', 'code'], 'uq_award_slots_code');
            $table->unique(['cycle_domain_id', 'slot_number'], 'uq_award_slots_number');
            $table->unique(['id', 'cycle_domain_id'], 'uq_award_slots_id_domain');
        });
        $this->addCheck('award_slots', 'chk_award_slots_number', 'slot_number >= 1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('award_slots');
        Schema::dropIfExists('cycle_domains');
        Schema::dropIfExists('award_domains');
        Schema::dropIfExists('cycle_stages');
        Schema::dropIfExists('award_cycles');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
