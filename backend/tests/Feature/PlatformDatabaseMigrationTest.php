<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * Applies the platform migrations on top of the develop baseline, rolls them
 * back and reapplies them on a disposable MySQL database, checking that
 * existing data survives every step.
 */
class PlatformDatabaseMigrationTest extends TestCase
{
    use UsesDisposableSchemaDatabase;

    private const USER = [
        'name' => 'Existing Account',
        'email' => 'existing.user@example.org',
        'email_verified_at' => '2026-10-01 08:30:00',
        'password' => '$2y$12$abcdefghijklmnopqrstuuJ0x6jvQ8n3o6kq1X8J7q5Y3a1z9b2c4',
        'remember_token' => 'remember-token-sample',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-02 09:00:00',
    ];

    private const NEW_USER_COLUMNS = ['phone', 'phone_verified_at', 'status', 'disabled_at', 'disabled_reason', 'last_login_at'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDisposableDatabase();
    }

    public function test_migrations_apply_roll_back_and_reapply_without_losing_existing_data(): void
    {
        $this->wipeDisposableDatabase();

        // 1. The develop baseline, with a representative existing account.
        $this->migrateBaseline();
        $userId = $this->schemaDb()->table('users')->insertGetId(self::USER);
        $this->schemaDb()->table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $userId,
            'name' => 'existing-token',
            'token' => str_repeat('b', 64),
            'created_at' => '2026-10-02 09:00:00',
            'updated_at' => '2026-10-02 09:00:00',
        ]);
        $baselineTables = $this->tableNames();

        // 2–3. Apply the platform migrations.
        $this->migrateAll();
        $this->assertSame(12, $this->schemaDb()->table('migrations')->where('batch', 2)->count());
        foreach (self::PLATFORM_TABLES as $table) {
            $this->assertContains($table, $this->tableNames(), "Missing table {$table}");
        }
        $this->assertCount(count($baselineTables) + count(self::PLATFORM_TABLES), $this->tableNames());
        $this->assertSame([], array_diff(self::NEW_USER_COLUMNS, $this->columnNames('users')));
        $this->assertExistingUserIntact($userId);
        $user = $this->schemaDb()->table('users')->find($userId);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->disabled_at);
        $this->assertEveryTableUsesInnoDbAndUnicodeCollation();

        // 4–5. Roll back this PR: back to the exact baseline, data intact.
        $this->rollbackLatestBatch();
        $this->assertEqualsCanonicalizing($baselineTables, $this->tableNames());
        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'],
            $this->columnNames('users'),
        );
        $this->assertSame(0, (int) $this->schemaDb()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
        )->total);
        $this->assertExistingUserIntact($userId);
        $this->assertSame(1, $this->schemaDb()->table('personal_access_tokens')->where('tokenable_id', $userId)->count());
        $this->assertSame(count(self::BASELINE_MIGRATIONS), $this->schemaDb()->table('migrations')->count());

        // 6. Reapply.
        $this->migrateAll();
        $this->assertSame(12, $this->schemaDb()->table('migrations')->where('batch', 2)->count());
        $this->assertExistingUserIntact($userId);
        $this->assertSame('active', $this->schemaDb()->table('users')->find($userId)->status);
    }

    public function test_the_disposable_database_guard_refuses_ordinary_database_names(): void
    {
        foreach (['al_sankri_award', 'laravel', 'al_sankri_award_test', 'schema_test', 'x;drop_schema_test'] as $name) {
            try {
                static::assertSafeDisposableDatabaseName($name);
                $this->fail("Guard accepted [{$name}].");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Refusing to use', $exception->getMessage());
            }
        }

        static::assertSafeDisposableDatabaseName('al_sankri_award_schema_test');
        $this->addToAssertionCount(1);
    }

    private function assertExistingUserIntact(int $userId): void
    {
        $user = (array) $this->schemaDb()->table('users')->find($userId);

        foreach (self::USER as $column => $value) {
            $this->assertSame($value, $user[$column], "users.{$column} changed");
        }
    }

    private function assertEveryTableUsesInnoDbAndUnicodeCollation(): void
    {
        $tables = $this->schemaDb()->select(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );

        foreach ($tables as $table) {
            $this->assertSame('InnoDB', $table->engine, "{$table->name} engine");
            $this->assertSame('utf8mb4_unicode_ci', $table->collation, "{$table->name} collation");
        }
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        return array_map(
            fn (object $row): string => $row->name,
            $this->schemaDb()->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'),
        );
    }

    /**
     * @return list<string>
     */
    private function columnNames(string $table): array
    {
        return array_map(
            fn (object $row): string => $row->name,
            $this->schemaDb()->select(
                'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table],
            ),
        );
    }
}
