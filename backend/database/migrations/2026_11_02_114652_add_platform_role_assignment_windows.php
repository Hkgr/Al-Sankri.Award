<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('revocation_source', 64)->nullable();
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE user_roles DROP CONSTRAINT chk_user_roles_revoked_by, DROP CONSTRAINT chk_user_roles_revoked');
            DB::statement("ALTER TABLE user_roles ADD CONSTRAINT chk_user_roles_revoked_by CHECK (revoked_at IS NULL OR revoked_by IS NOT NULL OR COALESCE(revocation_source, '') = 'candidate_role_policy')");
            DB::statement("ALTER TABLE user_roles ADD CONSTRAINT chk_user_roles_revoked CHECK (revoked_at IS NULL OR revoked_at >= assigned_at OR COALESCE(revocation_source, '') = 'candidate_role_policy')");
            DB::statement('ALTER TABLE user_roles ADD CONSTRAINT chk_user_roles_window CHECK (ends_at IS NULL OR ends_at > COALESCE(starts_at, assigned_at))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('user_roles')->whereNotNull('starts_at')->orWhereNotNull('ends_at')->orWhereNotNull('revocation_source')->exists()) {
            throw new RuntimeException('Role windows or automated reconciliation have been used. Preserve the history and restore a verified backup to reverse this migration.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE user_roles DROP CONSTRAINT chk_user_roles_window, DROP CONSTRAINT chk_user_roles_revoked_by, DROP CONSTRAINT chk_user_roles_revoked');
            DB::statement('ALTER TABLE user_roles ADD CONSTRAINT chk_user_roles_revoked_by CHECK (revoked_at IS NULL OR revoked_by IS NOT NULL)');
            DB::statement('ALTER TABLE user_roles ADD CONSTRAINT chk_user_roles_revoked CHECK (revoked_at IS NULL OR revoked_at >= assigned_at)');
        }
        Schema::table('user_roles', fn (Blueprint $table) => $table->dropColumn(['starts_at', 'ends_at', 'revocation_source']));
    }
};
