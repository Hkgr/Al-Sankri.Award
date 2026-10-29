<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the permission of the administrative review gate and gives it to the
 * existing `award_admin` role, so current administrators receive it through
 * their existing grants:
 * - `applications.review` «المراجعة الأولية لملفات الترشح»: refer a pending
 *   submitted application to its judging committee, or exclude and archive
 *   it (together with `applications.view_submitted`).
 *
 * Idempotent and additive: an existing permission or link is kept as it
 * is. Ownership is recorded separately for each row this migration creates
 * (permission.provisioned, role_permission.provisioned in audit_logs). A
 * rollback removes only rows it can match to those records, keeps
 * pre-existing rows and links added later by others, and refuses before any
 * change while the permission it created is linked to another role.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_29_900002_add_application_review_permission';

    private const PERMISSIONS = [
        'applications.review' => ['name' => 'المراجعة الأولية لملفات الترشح', 'module' => 'applications'],
    ];

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

            foreach (self::PERMISSIONS as $code => $permission) {
                $permissionId = DB::table('permissions')->where('code', $code)->value('id');
                if ($permissionId === null) {
                    $permissionId = DB::table('permissions')->insertGetId([
                        'code' => $code, 'name_ar' => $permission['name'], 'module' => $permission['module'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->mark(self::PERMISSION_CREATED, 'permission', (int) $permissionId, ['code' => $code]);
                }

                if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    $linkId = DB::table('role_permissions')->insertGetId(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
                    $this->mark(self::LINK_CREATED, 'role_permission', (int) $linkId, ['role_id' => (int) $roleId, 'permission_id' => (int) $permissionId]);
                }
            }
        });
    }

    /**
     * Reverse the migrations: only the links and permissions this migration
     * created, still exactly as created; refused while another role uses a
     * permission it created.
     */
    public function down(): void
    {
        $links = collect($this->marked(self::LINK_CREATED))
            ->filter(fn (array $mark): bool => DB::table('role_permissions')->where('id', $mark['id'])
                ->where('role_id', $mark['role_id'] ?? null)->where('permission_id', $mark['permission_id'] ?? null)->exists())
            ->pluck('id')->all();
        $permissions = collect($this->marked(self::PERMISSION_CREATED))
            ->filter(fn (array $mark): bool => isset(self::PERMISSIONS[$mark['code'] ?? ''])
                && DB::table('permissions')->where('id', $mark['id'])->where('code', $mark['code'])->exists())
            ->pluck('id')->all();

        $others = DB::table('role_permissions')->whereIn('permission_id', $permissions)->whereNotIn('id', $links)->count();
        if ($others > 0) {
            throw new RuntimeException("Cannot roll back: a permission created by this migration is linked to {$others} other role(s). Remove those links first; nothing was changed.");
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
