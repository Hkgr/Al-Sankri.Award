<?php

namespace Tests\Feature;

use App\Auth\GoogleAccounts;
use App\Auth\GoogleIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * Applies the platform migrations on top of the develop baseline, rolls them
 * back and reapplies them on a disposable MySQL or MariaDB database, checking that
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

        // 2–3. Apply the platform and account migrations.
        $this->migrateAll();
        $this->assertSame(12 + count(self::ACCOUNT_MIGRATIONS), $this->schemaDb()->table('migrations')->where('batch', 2)->count());
        foreach ([...self::PLATFORM_TABLES, ...self::ACCOUNT_TABLES] as $table) {
            $this->assertContains($table, $this->tableNames(), "Missing table {$table}");
        }
        $this->assertCount(count($baselineTables) + count(self::PLATFORM_TABLES) + count(self::ACCOUNT_TABLES), $this->tableNames());
        $this->assertSame('YES', $this->passwordNullability());
        $this->assertSame([], array_diff(self::NEW_USER_COLUMNS, $this->columnNames('users')));
        $this->assertExistingUserIntact($userId);
        $user = $this->schemaDb()->table('users')->find($userId);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->disabled_at);
        $this->assertEveryTableUsesInnoDbAndUnicodeCollation();
        $this->assertEveryNamedConstraintExists();

        // 4–5. Roll back this PR: back to the exact baseline, data intact.
        $this->rollbackLatestBatch();
        $this->assertEqualsCanonicalizing($baselineTables, $this->tableNames());
        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at'],
            $this->columnNames('users'),
        );
        $this->assertSame('NO', $this->passwordNullability());
        $this->assertSame(0, (int) $this->schemaDb()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'
        )->total);
        $this->assertExistingUserIntact($userId);
        $this->assertSame(1, $this->schemaDb()->table('personal_access_tokens')->where('tokenable_id', $userId)->count());
        $this->assertSame(count(self::BASELINE_MIGRATIONS), $this->schemaDb()->table('migrations')->count());

        // 6. Reapply.
        $this->migrateAll();
        $this->assertSame(12 + count(self::ACCOUNT_MIGRATIONS), $this->schemaDb()->table('migrations')->where('batch', 2)->count());
        $this->assertExistingUserIntact($userId);
        $this->assertSame('active', $this->schemaDb()->table('users')->find($userId)->status);
    }

    public function test_the_account_migrations_apply_on_a_deployed_platform_schema(): void
    {
        // The production state: baseline and platform foundation already applied, with an account.
        $this->wipeDisposableDatabase();
        $this->migrateBaseline();
        $this->migrateOnly($this->platformMigrations());
        $userId = $this->schemaDb()->table('users')->insertGetId(self::USER);
        $foreignKeysToUsers = $this->foreignKeysTo('users');
        $this->assertSame('NO', $this->passwordNullability());

        $this->migrateOnly(self::ACCOUNT_MIGRATIONS);
        $this->assertSame(count(self::ACCOUNT_MIGRATIONS), $this->schemaDb()->table('migrations')->where('batch', 3)->count());
        $this->assertSame('YES', $this->passwordNullability());
        $this->assertSame(self::USER['password'], $this->schemaDb()->table('users')->find($userId)->password);
        $this->assertEqualsCanonicalizing([...$foreignKeysToUsers, 'fk_oauth_identities_user'], $this->foreignKeysTo('users'));

        // Only the new batch rolls back; the deployed schema and the hash stay.
        $this->rollbackLatestBatch();
        $this->assertSame('NO', $this->passwordNullability());
        $this->assertNotContains('oauth_identities', $this->tableNames());
        $this->assertSame($foreignKeysToUsers, $this->foreignKeysTo('users'));
        $this->assertExistingUserIntact($userId);

        $this->migrateOnly(self::ACCOUNT_MIGRATIONS);
        $this->assertExistingUserIntact($userId);
    }

    public function test_rolling_back_refuses_to_strand_passwordless_accounts(): void
    {
        // Production-like: the foundation in one batch, the account migrations in the next.
        $this->wipeDisposableDatabase();
        $this->migrateBaseline();
        $this->migrateOnly($this->platformMigrations());
        $this->migrateOnly(self::ACCOUNT_MIGRATIONS);
        $userId = $this->schemaDb()->table('users')->insertGetId([
            'name' => 'Google', 'email' => 'google.only@example.org', 'password' => null, 'email_verified_at' => '2026-10-20 10:00:00',
        ]);
        $this->schemaDb()->table('oauth_identities')->insert([
            'user_id' => $userId, 'provider' => 'google', 'provider_subject' => '109876543210987654321', 'linked_at' => '2026-10-20 10:00:00',
        ]);

        try {
            $this->rollbackLatestBatch();
            $this->fail('The rollback ran despite a passwordless account.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 account(s) have no password', $exception->getMessage());
        }

        // Nothing was dropped or forgotten.
        $this->assertContains('oauth_identities', $this->tableNames());
        $this->assertSame(1, $this->schemaDb()->table('oauth_identities')->where('user_id', $userId)->count());
        $this->assertSame(1, $this->schemaDb()->table('users')->where('id', $userId)->whereNull('password')->count());
        $this->assertSame('YES', $this->passwordNullability());
        $this->assertEqualsCanonicalizing(self::ACCOUNT_MIGRATIONS, $this->schemaDb()->table('migrations')->where('batch', 3)->pluck('migration')->all());
        $this->assertPreservedIdentitySignsIn($userId, '109876543210987654321');

        // Once the owner has set a password, the same rollback succeeds and reapplies.
        $this->schemaDb()->table('users')->where('id', $userId)->update(['password' => self::USER['password']]);
        $this->rollbackLatestBatch();
        $this->assertNotContains('oauth_identities', $this->tableNames());
        $this->assertSame('NO', $this->passwordNullability());
        $this->assertSame(0, $this->schemaDb()->table('migrations')->whereIn('migration', self::ACCOUNT_MIGRATIONS)->count());
        $this->migrateOnly(self::ACCOUNT_MIGRATIONS);
        $this->assertSame('YES', $this->passwordNullability());
        $this->assertSame(self::USER['password'], $this->schemaDb()->table('users')->find($userId)->password);
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
     * Every CHECK, foreign key and unique key named in the platform migrations
     * must exist on this engine, so none can be skipped silently.
     */
    private function assertEveryNamedConstraintExists(): void
    {
        $source = implode("\n", array_map('file_get_contents', glob(database_path('migrations/2026_10_27_*.php'))));
        preg_match_all("/'((?:chk|fk|uq)_[a-z0-9_]+)'/", $source, $matches);
        $declared = array_values(array_unique($matches[1]));

        $existing = array_values(array_filter(
            array_map(fn (object $row): string => $row->name, $this->schemaDb()->select(
                'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()'
            )),
            fn (string $name): bool => preg_match('/^(chk|fk|uq)_/', $name) === 1,
        ));

        $this->assertGreaterThan(150, count($declared));
        $this->assertEqualsCanonicalizing($declared, $existing);
    }

    /**
     * Resolves a Google sign-in for the preserved identity against the
     * disposable database, through the application's own account resolver.
     */
    private function assertPreservedIdentitySignsIn(int $userId, string $subject): void
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(self::SCHEMA_CONNECTION);

        try {
            [$outcome, $user] = app(GoogleAccounts::class)->signIn(new GoogleIdentity($subject, 'renamed@gmail.com', 'Google', true, null));
        } finally {
            DB::setDefaultConnection($previous);
        }

        $this->assertSame(GoogleAccounts::SIGNED_IN, $outcome);
        $this->assertSame($userId, (int) $user->getKey());
    }

    private function passwordNullability(): string
    {
        return (string) $this->schemaDb()->selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'"
        )->nullable;
    }

    /**
     * @return list<string>
     */
    private function foreignKeysTo(string $table): array
    {
        return array_map(
            fn (object $row): string => $row->name,
            $this->schemaDb()->select(
                'SELECT CONSTRAINT_NAME AS name FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? ORDER BY CONSTRAINT_NAME',
                [$table],
            ),
        );
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
