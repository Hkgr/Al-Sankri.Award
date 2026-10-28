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
 * is. Ownership is recorded separately for each row this migration
 * creates, in audit_logs:
 * - `permission.provisioned` (subject: the permission) when it creates the
 *   permission;
 * - `role_permission.provisioned` (subject: the role_permissions row) when it
 *   creates the award_admin link.
 * A rollback removes only rows it can match to these records (id and
 * contents), so pre-existing permissions and links, and links added later by
 * anyone else, stay. It refuses, before changing anything, while a
 * permission it created is linked to any other role.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_28_700001_add_registered_users_permission';

    private const CODE = 'users.view_registered';

    private const PERMISSION_CREATED = 'permission.provisioned';

    private const LINK_CREATED = 'role_permission.provisioned';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('code', 'award_admin')->where('scope', 'platform')->value('id');
            if ($roleId === null) {
                throw new RuntimeException('The award_admin role is missing: run the migrations in order.');
            }

            $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => self::CODE, 'name_ar' => 'عرض الحسابات المسجّلة', 'module' => 'users',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->mark(self::PERMISSION_CREATED, 'permission', (int) $permissionId, ['code' => self::CODE]);
            }

            if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                $linkId = DB::table('role_permissions')->insertGetId(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
                $this->mark(self::LINK_CREATED, 'role_permission', (int) $linkId, ['role_id' => (int) $roleId, 'permission_id' => (int) $permissionId]);
            }
        });
    }

    /**
     * Reverse the migrations: removes the award_admin link only if this
     * migration created it, and the permission only if this migration
     * created it and nothing else links to it any more. Refused, before any
     * change, while a role other than through that link uses a permission
     * this migration created.
     */
    public function down(): void
    {
        // The rows this migration created that still exist exactly as created.
        $links = collect($this->marked(self::LINK_CREATED))
            ->filter(fn (array $mark): bool => DB::table('role_permissions')->where('id', $mark['id'])
                ->where('role_id', $mark['role_id'] ?? null)->where('permission_id', $mark['permission_id'] ?? null)->exists())
            ->pluck('id')->all();
        $permissions = collect($this->marked(self::PERMISSION_CREATED))
            ->filter(fn (array $mark): bool => DB::table('permissions')->where('id', $mark['id'])->where('code', self::CODE)->exists())
            ->pluck('id')->all();

        $others = DB::table('role_permissions')->whereIn('permission_id', $permissions)->whereNotIn('id', $links)->count();
        if ($others > 0) {
            throw new RuntimeException('Cannot roll back: the permission '.self::CODE." created by this migration is linked to {$others} other role(s). Remove those links first; nothing was changed.");
        }

        DB::transaction(function () use ($links, $permissions): void {
            DB::table('role_permissions')->whereIn('id', $links)->delete();
            DB::table('permissions')->whereIn('id', $permissions)->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mark(string $action, string $subject, int $id, array $data): void
    {
        DB::table('audit_logs')->insert([
            'action' => $action,
            'subject_type' => $subject,
            'subject_id' => $id,
            'after_data' => json_encode($data + ['migration' => self::MIGRATION]),
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * This migration's records of one kind: the row id and what it held.
     *
     * @return list<array<string, mixed>>
     */
    private function marked(string $action): array
    {
        return DB::table('audit_logs')->where('action', $action)->where('after_data', 'like', '%'.self::MIGRATION.'%')
            ->get(['subject_id', 'after_data'])
            ->map(fn (object $row): array => ['id' => (int) $row->subject_id] + (array) json_decode((string) $row->after_data, true))
            ->all();
    }
};
