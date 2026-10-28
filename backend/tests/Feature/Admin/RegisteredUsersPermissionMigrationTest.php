<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Ownership in 2026_10_28_700001_add_registered_users_permission: the
 * permission and the award_admin link are owned separately; a rollback
 * removes only what this migration created, keeps everything that existed
 * before or was added by others, and refuses (changing nothing) while a
 * permission it created is linked to another role.
 */
class RegisteredUsersPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'users.view_registered';

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_28_700001_add_registered_users_permission.php');
        // Start each case from "before this migration".
        $this->migration->down();
        $this->assertSame(0, DB::table('permissions')->where('code', self::CODE)->count());
    }

    public function test_a_normal_install_is_idempotent_and_rolls_back_cleanly(): void
    {
        $this->migration->up();
        $this->migration->up();
        $this->assertSame(1, DB::table('permissions')->where('code', self::CODE)->count());
        $this->assertSame([self::CODE], $this->adminPermissions());

        $this->migration->down();
        $this->assertSame(0, DB::table('permissions')->where('code', self::CODE)->count());
        $this->assertSame([], $this->adminPermissions());
    }

    public function test_a_pre_existing_permission_keeps_living_and_only_the_created_link_goes(): void
    {
        $permission = $this->permission();

        $this->migration->up();
        $this->assertSame([self::CODE], $this->adminPermissions(), 'administrators receive it through their role');

        $this->migration->down();
        $this->assertTrue(DB::table('permissions')->where('id', $permission)->exists(), 'the pre-existing permission stays');
        $this->assertSame([], $this->adminPermissions(), 'the link this migration created is removed');
    }

    public function test_a_pre_existing_permission_and_link_are_left_untouched(): void
    {
        $permission = $this->permission();
        $link = DB::table('role_permissions')->insertGetId(['role_id' => $this->roleId('award_admin'), 'permission_id' => $permission, 'created_at' => now()]);

        $this->migration->up();
        $this->assertSame(1, DB::table('role_permissions')->where('permission_id', $permission)->count());

        $this->migration->down();
        $this->assertTrue(DB::table('permissions')->where('id', $permission)->exists());
        $this->assertTrue(DB::table('role_permissions')->where('id', $link)->exists(), 'the pre-existing link stays');
    }

    public function test_a_created_permission_linked_to_another_role_refuses_rollback_without_changes(): void
    {
        $this->migration->up();
        $other = DB::table('role_permissions')->insertGetId([
            'role_id' => $this->roleId('judge'),
            'permission_id' => DB::table('permissions')->where('code', self::CODE)->value('id'),
            'created_at' => now(),
        ]);
        $before = DB::table('role_permissions')->orderBy('id')->get();

        try {
            $this->migration->down();
            $this->fail('the rollback should be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('linked to 1 other role', $refusal->getMessage());
        }
        $this->assertEquals($before, DB::table('role_permissions')->orderBy('id')->get(), 'nothing was changed');
        $this->assertSame(1, DB::table('permissions')->where('code', self::CODE)->count());

        // Once that link is gone, the rollback removes only this migration's rows.
        DB::table('role_permissions')->where('id', $other)->delete();
        $this->migration->down();
        $this->assertSame(0, DB::table('permissions')->where('code', self::CODE)->count());
    }

    private function permission(): int
    {
        return (int) DB::table('permissions')->insertGetId(['code' => self::CODE, 'name_ar' => 'موجودة مسبقاً', 'module' => 'users', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    /**
     * @return list<string>
     */
    private function adminPermissions(): array
    {
        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $this->roleId('award_admin'))->where('permissions.code', self::CODE)
            ->pluck('permissions.code')->all();
    }
}
