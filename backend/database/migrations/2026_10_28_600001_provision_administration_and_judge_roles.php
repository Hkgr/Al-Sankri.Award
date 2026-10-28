<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the two roles of the first administration phase, and the two
 * permissions the administration dashboard checks (App\Admin\AdminAccess):
 *
 * - `award_admin` «مدير الجائزة» (platform): carries
 *   `applications.view_submitted` and `committees.manage_judging`. Granted
 *   only by `php artisan admin:grant-access --email=…`, never by default.
 * - `judge` «حكَم» (cycle): granted in cycle_user_roles together with a
 *   judging-committee membership, by the administration workflow. It
 *   carries no permission: there is no judge portal yet.
 *
 * Idempotent and additive: existing roles, permissions and grants are kept
 * exactly as they are (an existing role with another scope is refused, as
 * for the candidate role). What this migration creates is recorded in
 * audit_logs (action role.provisioned / permission.provisioned), so a
 * rollback removes only its own rows.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_28_600001_provision_administration_and_judge_roles';

    private const ROLES = [
        'award_admin' => ['name' => 'مدير الجائزة', 'scope' => 'platform', 'description' => 'يدير ملفات الترشح المرسلة ولجان الحكام في لوحة الإدارة.'],
        'judge' => ['name' => 'حكَم', 'scope' => 'cycle', 'description' => 'عضو لجنة تحكيم مجال رئيسي في دورة واحدة.'],
    ];

    private const PERMISSIONS = [
        'applications.view_submitted' => ['name' => 'عرض ملفات الترشح المرسلة', 'module' => 'applications'],
        'committees.manage_judging' => ['name' => 'إدارة لجان الحكام', 'module' => 'committees'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::ROLES as $code => $role) {
            $existing = DB::table('roles')->where('code', $code)->first();
            if ($existing !== null && $existing->scope !== $role['scope']) {
                throw new RuntimeException("A \"{$code}\" role already exists with scope [{$existing->scope}]; it must be a {$role['scope']} role.");
            }
        }

        DB::transaction(function (): void {
            foreach (self::ROLES as $code => $role) {
                if (DB::table('roles')->where('code', $code)->exists()) {
                    continue;
                }
                $id = DB::table('roles')->insertGetId([
                    'code' => $code, 'name_ar' => $role['name'], 'description' => $role['description'], 'scope' => $role['scope'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->mark('role.provisioned', 'role', $id, $code);
            }

            foreach (self::PERMISSIONS as $code => $permission) {
                if (DB::table('permissions')->where('code', $code)->exists()) {
                    continue;
                }
                $id = DB::table('permissions')->insertGetId([
                    'code' => $code, 'name_ar' => $permission['name'], 'module' => $permission['module'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->mark('permission.provisioned', 'permission', $id, $code);
            }

            $adminRole = DB::table('roles')->where('code', 'award_admin')->value('id');
            foreach (array_keys(self::PERMISSIONS) as $code) {
                $permissionId = DB::table('permissions')->where('code', $code)->value('id');
                if (! DB::table('role_permissions')->where('role_id', $adminRole)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $adminRole, 'permission_id' => $permissionId, 'created_at' => now()]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Refused while any account holds or held one of these roles (grants
     * are account history). Otherwise removes only the roles and
     * permissions this migration created, with their role_permissions rows.
     */
    public function down(): void
    {
        $roleIds = DB::table('roles')->whereIn('code', array_keys(self::ROLES))->pluck('id')->all();
        $grants = DB::table('user_roles')->whereIn('role_id', $roleIds)->count() + DB::table('cycle_user_roles')->whereIn('role_id', $roleIds)->count();

        if ($grants > 0) {
            throw new RuntimeException("Cannot remove the award_admin and judge roles: {$grants} grant(s) reference them.");
        }

        DB::transaction(function (): void {
            foreach ([['role.provisioned', 'role', 'roles'], ['permission.provisioned', 'permission', 'permissions']] as [$action, $subject, $table]) {
                $ids = DB::table('audit_logs')->where('action', $action)->where('subject_type', $subject)
                    ->where('after_data', 'like', '%'.self::MIGRATION.'%')->pluck('subject_id')->all();
                if ($ids === []) {
                    continue;
                }
                DB::table('role_permissions')->whereIn($subject === 'role' ? 'role_id' : 'permission_id', $ids)->delete();
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        });
    }

    private function mark(string $action, string $subject, int $id, string $code): void
    {
        DB::table('audit_logs')->insert([
            'action' => $action,
            'subject_type' => $subject,
            'subject_id' => $id,
            'after_data' => json_encode(['code' => $code, 'migration' => self::MIGRATION]),
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
};
