<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role grants that apply inside one award cycle only.
 *
 * The generated role_scope column and the composite foreign key onto
 * roles (id, scope) accept cycle-scoped roles only. At most one unrevoked
 * grant may exist per cycle, user and role; revoked rows remain as history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cycle_user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('award_cycles', 'id', 'fk_cycle_user_roles_cycle')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_cycle_user_roles_user')->restrictOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->string('role_scope', 16)->storedAs("'cycle'");
            $table->foreignId('assigned_by')->nullable()->constrained('users', 'id', 'fk_cycle_user_roles_assigned_by')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users', 'id', 'fk_cycle_user_roles_revoked_by')->restrictOnDelete();
            $table->string('revocation_reason', 1000)->nullable();
            $table->unsignedTinyInteger('is_current')->nullable()->virtualAs('CASE WHEN revoked_at IS NULL THEN 1 END');
            $table->datetimes();

            $table->foreign(['role_id', 'role_scope'], 'fk_cycle_user_roles_role')->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->unique(['cycle_id', 'user_id', 'role_id', 'is_current'], 'uq_cycle_user_roles_current');
            $table->index(['user_id', 'cycle_id'], 'idx_cycle_user_roles_user');
        });
        $this->addCheck('chk_cycle_user_roles_window', 'ends_at IS NULL OR ends_at > starts_at');
        $this->addCheck('chk_cycle_user_roles_revoked', '(revoked_at IS NULL) OR (revoked_by IS NOT NULL AND revocation_reason IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cycle_user_roles');
    }

    private function addCheck(string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `cycle_user_roles` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
