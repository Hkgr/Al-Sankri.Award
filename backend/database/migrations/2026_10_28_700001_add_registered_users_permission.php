<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the permission `users.view_registered` («عرض الحسابات المسجّلة»),
 * which the read-only «المستخدمون» tab of the administration dashboard
 * checks, and gives it to the existing `award_admin` role. Accounts that
 * already hold `award_admin` receive it through their existing grants; the
 * grant command is not run again.
 *
 * Idempotent and additive: an existing permission or link is kept as it
 * is. What this migration creates is recorded in audit_logs
 * (permission.provisioned), so a rollback removes only its own rows.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_28_700001_add_registered_users_permission';

    private const CODE = 'users.view_registered';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => self::CODE, 'name_ar' => 'عرض الحسابات المسجّلة', 'module' => 'users',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('audit_logs')->insert([
                    'action' => 'permission.provisioned',
                    'subject_type' => 'permission',
                    'subject_id' => $permissionId,
                    'after_data' => json_encode(['code' => self::CODE, 'migration' => self::MIGRATION]),
                    'occurred_at' => now()->format('Y-m-d H:i:s.u'),
                ]);
            }

            $roleId = DB::table('roles')->where('code', 'award_admin')->where('scope', 'platform')->value('id');
            if ($roleId === null) {
                throw new RuntimeException('The award_admin role is missing: run the migrations in order.');
            }
            if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        });
    }

    /**
     * Reverse the migrations: removes the permission (and its role links)
     * only when this migration created it.
     */
    public function down(): void
    {
        $ids = DB::table('audit_logs')->where('action', 'permission.provisioned')->where('subject_type', 'permission')
            ->where('after_data', 'like', '%'.self::MIGRATION.'%')->pluck('subject_id')->all();

        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        });
    }
};
