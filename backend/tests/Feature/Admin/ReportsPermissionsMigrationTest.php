<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Ownership in 2026_10_29_800001_add_reports_and_audit_permissions: each
 * permission and each award_admin link is owned separately; a rollback
 * removes only what this migration created and refuses, changing nothing,
 * while another role uses a permission it created.
 */
class ReportsPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const CODES = ['reports.export', 'audit.view', 'logs.view_system'];

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_29_800001_add_reports_and_audit_permissions.php');
        $this->migration->down();
        $this->assertSame(0, DB::table('permissions')->whereIn('code', self::CODES)->count());
    }

    public function test_install_is_idempotent_and_rolls_back_cleanly(): void
    {
        $this->migration->up();
        $this->migration->up();
        $this->assertSame(self::sorted(self::CODES), $this->adminCodes());

        $this->migration->down();
        $this->assertSame(0, DB::table('permissions')->whereIn('code', self::CODES)->count());
        $this->assertSame([], $this->adminCodes());
    }

    public function test_pre_existing_permissions_and_links_are_kept(): void
    {
        // audit.view existed without the link; logs.view_system existed with it.
        $audit = $this->permission('audit.view');
        $logs = $this->permission('logs.view_system');
        $link = DB::table('role_permissions')->insertGetId(['role_id' => $this->roleId('award_admin'), 'permission_id' => $logs, 'created_at' => now()]);

        $this->migration->up();
        $this->assertSame(self::sorted(self::CODES), $this->adminCodes());

        $this->migration->down();
        $this->assertTrue(DB::table('permissions')->where('id', $audit)->exists());
        $this->assertTrue(DB::table('permissions')->where('id', $logs)->exists());
        $this->assertTrue(DB::table('role_permissions')->where('id', $link)->exists(), 'the pre-existing link stays');
        $this->assertSame(['logs.view_system'], $this->adminCodes(), 'only the links this migration created go');
        $this->assertSame(0, DB::table('permissions')->where('code', 'reports.export')->count());
    }

    public function test_a_created_permission_used_by_another_role_refuses_rollback(): void
    {
        $this->migration->up();
        DB::table('role_permissions')->insert(['role_id' => $this->roleId('judge'), 'permission_id' => DB::table('permissions')->where('code', 'audit.view')->value('id'), 'created_at' => now()]);
        $before = DB::table('role_permissions')->orderBy('id')->get();

        try {
            $this->migration->down();
            $this->fail('the rollback should be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('linked to 1 other role', $refusal->getMessage());
        }
        $this->assertEquals($before, DB::table('role_permissions')->orderBy('id')->get(), 'nothing was changed');
        $this->assertSame(3, DB::table('permissions')->whereIn('code', self::CODES)->count());
    }

    private function permission(string $code): int
    {
        return (int) DB::table('permissions')->insertGetId(['code' => $code, 'name_ar' => 'موجودة مسبقاً', 'module' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    /**
     * @return list<string>
     */
    private function adminCodes(): array
    {
        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $this->roleId('award_admin'))->whereIn('permissions.code', self::CODES)
            ->orderBy('permissions.code')->pluck('permissions.code')->all();
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private static function sorted(array $codes): array
    {
        sort($codes);

        return $codes;
    }
}
