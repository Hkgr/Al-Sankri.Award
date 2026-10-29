<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adds `schedules.manage` for «المواعيد», gives it to `award_admin`, and sets
 * the first-cycle windows: registration through 15 November 2026, judging
 * from the end of that day through 25 November 2026 (Asia/Damascus).
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_29_900005_add_schedule_permission_and_cycle_windows';

    private const PERMISSION = 'schedules.manage';

    private const PERMISSION_CREATED = 'permission.provisioned';

    private const LINK_CREATED = 'role_permission.provisioned';

    private const STAGE_SEEDED = 'cycle_stage.schedule_seeded';

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

            $permissionId = DB::table('permissions')->where('code', self::PERMISSION)->value('id');
            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => self::PERMISSION, 'name_ar' => 'إدارة المواعيد', 'module' => 'schedules',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->mark(self::PERMISSION_CREATED, 'permission', (int) $permissionId, ['code' => self::PERMISSION]);
            }
            if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                $linkId = DB::table('role_permissions')->insertGetId(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
                $this->mark(self::LINK_CREATED, 'role_permission', (int) $linkId, ['role_id' => (int) $roleId, 'permission_id' => (int) $permissionId]);
            }

            $this->seedWindows();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $seeded = collect($this->marked(self::STAGE_SEEDED));
        foreach ($seeded as $mark) {
            if (($mark['created'] ?? false) === true) {
                continue;
            }
            $current = DB::table('cycle_stages')->where('id', $mark['id'])->first();
            if ($current === null) {
                continue;
            }
            if ((string) $current->opens_at !== (string) ($mark['opens_at'] ?? null)
                || (string) $current->closes_at !== (string) ($mark['closes_at'] ?? null)) {
                throw new RuntimeException('Cannot roll back: a seeded stage window was changed later. Nothing was changed.');
            }
        }

        $links = collect($this->marked(self::LINK_CREATED))
            ->filter(fn (array $mark): bool => DB::table('role_permissions')->where('id', $mark['id'])
                ->where('role_id', $mark['role_id'] ?? null)->where('permission_id', $mark['permission_id'] ?? null)->exists())
            ->pluck('id')->all();
        $permissions = collect($this->marked(self::PERMISSION_CREATED))
            ->filter(fn (array $mark): bool => ($mark['code'] ?? null) === self::PERMISSION
                && DB::table('permissions')->where('id', $mark['id'])->where('code', self::PERMISSION)->exists())
            ->pluck('id')->all();
        $others = DB::table('role_permissions')->whereIn('permission_id', $permissions)->whereNotIn('id', $links)->count();
        if ($others > 0) {
            throw new RuntimeException("Cannot roll back: a permission created by this migration is linked to {$others} other role(s). Nothing was changed.");
        }

        DB::transaction(function () use ($seeded, $links, $permissions): void {
            foreach ($seeded as $mark) {
                if (($mark['created'] ?? false) === true) {
                    DB::table('cycle_stages')->where('id', $mark['id'])->delete();
                } else {
                    DB::table('cycle_stages')->where('id', $mark['id'])->update([
                        'opens_at' => $mark['previous_opens_at'] ?? null,
                        'closes_at' => $mark['previous_closes_at'] ?? null,
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('role_permissions')->whereIn('id', $links)->delete();
            DB::table('permissions')->whereIn('id', $permissions)->delete();
            DB::table('audit_logs')->where('action', self::STAGE_SEEDED)->where('after_data', 'like', '%'.self::MIGRATION.'%')->delete();
        });
    }

    private function seedWindows(): void
    {
        $cycles = DB::table('award_cycles')->where('status', 'published')->orderBy('id')->get(['id', 'timezone']);
        foreach ($cycles as $cycle) {
            $tz = $cycle->timezone ?: 'Asia/Damascus';
            $nominationOpens = Carbon::now($tz)->startOfDay()->utc();
            $nominationCloses = Carbon::create(2026, 11, 16, 0, 0, 0, $tz)->utc();
            $judgingCloses = Carbon::create(2026, 11, 26, 0, 0, 0, $tz)->utc();

            $nomination = DB::table('cycle_stages')->where('cycle_id', $cycle->id)->where('code', 'nomination_details')->first();
            if ($nomination !== null) {
                $previous = ['opens_at' => $nomination->opens_at, 'closes_at' => $nomination->closes_at];
                DB::table('cycle_stages')->where('id', $nomination->id)->update([
                    'opens_at' => $nominationOpens,
                    'closes_at' => $nominationCloses,
                    'name_ar' => 'فترة الترشح والتسجيل',
                    'last_change_reason' => 'schedule seed 2026-11',
                    'updated_at' => now(),
                ]);
                $this->mark(self::STAGE_SEEDED, 'cycle_stage', (int) $nomination->id, [
                    'code' => 'nomination_details', 'cycle_id' => (int) $cycle->id, 'created' => false,
                    'opens_at' => $nominationOpens->format('Y-m-d H:i:s'), 'closes_at' => $nominationCloses->format('Y-m-d H:i:s'),
                    'previous_opens_at' => $previous['opens_at'], 'previous_closes_at' => $previous['closes_at'],
                ]);
            }

            $judging = DB::table('cycle_stages')->where('cycle_id', $cycle->id)->where('code', 'judging')->first();
            if ($judging === null) {
                $enabler = $nomination->enabled_by ?? DB::table('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where('roles.code', 'award_admin')->whereNull('user_roles.revoked_at')
                    ->orderBy('user_roles.user_id')->value('user_roles.user_id');
                if ($enabler === null) {
                    continue;
                }
                $id = DB::table('cycle_stages')->insertGetId([
                    'cycle_id' => $cycle->id, 'code' => 'judging', 'name_ar' => 'فترة التحكيم',
                    'opens_at' => $nominationCloses, 'closes_at' => $judgingCloses,
                    'status' => 'enabled', 'display_order' => 3,
                    'enabled_by' => $enabler, 'enabled_at' => now(),
                    'last_change_reason' => 'schedule seed 2026-11',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->mark(self::STAGE_SEEDED, 'cycle_stage', (int) $id, [
                    'code' => 'judging', 'cycle_id' => (int) $cycle->id, 'created' => true,
                    'opens_at' => $nominationCloses->format('Y-m-d H:i:s'), 'closes_at' => $judgingCloses->format('Y-m-d H:i:s'),
                ]);
            } else {
                $previous = ['opens_at' => $judging->opens_at, 'closes_at' => $judging->closes_at];
                DB::table('cycle_stages')->where('id', $judging->id)->update([
                    'opens_at' => $nominationCloses, 'closes_at' => $judgingCloses,
                    'status' => 'enabled', 'name_ar' => 'فترة التحكيم',
                    'last_change_reason' => 'schedule seed 2026-11', 'updated_at' => now(),
                ]);
                $this->mark(self::STAGE_SEEDED, 'cycle_stage', (int) $judging->id, [
                    'code' => 'judging', 'cycle_id' => (int) $cycle->id, 'created' => false,
                    'opens_at' => $nominationCloses->format('Y-m-d H:i:s'), 'closes_at' => $judgingCloses->format('Y-m-d H:i:s'),
                    'previous_opens_at' => $previous['opens_at'], 'previous_closes_at' => $previous['closes_at'],
                ]);
            }
        }
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
            'after_data' => json_encode($data + ['migration' => self::MIGRATION], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
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
