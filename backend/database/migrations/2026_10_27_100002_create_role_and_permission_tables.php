<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles, permissions and platform-wide (global) role grants.
 *
 * Cycle-scoped grants live in cycle_user_roles. The generated role_scope
 * column plus a composite foreign key onto roles (id, scope) stop a
 * cycle-scoped role from being granted globally here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique('uq_roles_code');
            $table->string('name_ar', 150);
            $table->text('description')->nullable();
            $table->string('scope', 16);
            $table->datetimes();

            $table->unique(['id', 'scope'], 'uq_roles_id_scope');
            $table->index('scope', 'idx_roles_scope');
        });
        $this->addCheck('roles', 'chk_roles_scope', "scope IN ('platform', 'cycle')");

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique('uq_permissions_code');
            $table->string('name_ar', 150);
            $table->string('module', 64);
            $table->datetimes();

            $table->index('module', 'idx_permissions_module');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles', 'id', 'fk_role_permissions_role')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions', 'id', 'fk_role_permissions_permission')->cascadeOnDelete();
            $table->dateTime('created_at')->nullable();

            $table->unique(['role_id', 'permission_id'], 'uq_role_permissions_pair');
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_user_roles_user')->restrictOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->string('role_scope', 16)->storedAs("'platform'");
            $table->foreignId('assigned_by')->nullable()->constrained('users', 'id', 'fk_user_roles_assigned_by')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users', 'id', 'fk_user_roles_revoked_by')->restrictOnDelete();
            $table->unsignedTinyInteger('is_current')->nullable()->virtualAs('CASE WHEN revoked_at IS NULL THEN 1 END');
            $table->datetimes();

            $table->foreign(['role_id', 'role_scope'], 'fk_user_roles_role')->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->unique(['user_id', 'role_id', 'is_current'], 'uq_user_roles_current');
        });
        $this->addCheck('user_roles', 'chk_user_roles_revoked', 'revoked_at IS NULL OR revoked_at >= assigned_at');
        $this->addCheck('user_roles', 'chk_user_roles_revoked_by', '(revoked_at IS NULL) OR (revoked_by IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
