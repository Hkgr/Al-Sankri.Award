<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * Rebuilds, in a disposable MariaDB database, the state left in production
 * when the original migration 100005 created applicants and then failed on
 * its REGEXP_LIKE() check, and proves that the recovery documented in
 * docs/platform/database-rules.md §8 lets the migrations finish.
 */
class PlatformDatabaseRecoveryTest extends TestCase
{
    use UsesDisposableSchemaDatabase;

    private const RECOVERY_DOCUMENT = '../docs/platform/database-rules.md';

    private const DOCUMENTED_DATABASE_GUARD = "DATABASE() <> 'al_sankri_award'";

    private const SIGNAL_ERROR = 1644;

    /**
     * @var list<string>
     */
    private const COMPLETED_PLATFORM_MIGRATIONS = [
        '2026_10_27_100001_extend_users_for_platform_accounts',
        '2026_10_27_100002_create_role_and_permission_tables',
        '2026_10_27_100003_create_award_cycle_and_domain_tables',
        '2026_10_27_100004_create_cycle_user_roles_table',
    ];

    /**
     * @var list<string>
     */
    private const MISSING_APPLICATION_TABLES = ['applicant_members', 'applications', 'application_locations', 'application_versions'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDisposableDatabase();

        if (! $this->isMariaDb()) {
            $this->markTestSkipped('The interrupted state only arises on MariaDB, which rejects the original REGEXP_LIKE() check.');
        }
    }

    public function test_the_documented_recovery_lets_an_interrupted_installation_finish(): void
    {
        $userId = $this->reproduceInterruptedInstallation();
        $user = (array) $this->schemaDb()->table('users')->find($userId);
        $partialApplicants = $this->tableSignature('applicants');
        $this->assertInterruptedState();

        // The corrected migrations alone stop at the leftover table and change nothing.
        try {
            $this->migrateAll();
            $this->fail('migrate succeeded although applicants already exists.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1050', $exception->getMessage());
        }
        $this->assertInterruptedState();

        // Every unexpected state aborts the recovery and leaves applicants in place.
        $recovery = $this->recoverySql();
        $this->assertRecoveryAborts($this->documentedRecoverySql(), 'not connected to the expected database');

        $this->schemaDb()->table('applicants')->insert(['type' => 'initiative', 'name_ar' => 'مبادرة', 'representative_user_id' => $userId]);
        $this->assertRecoveryAborts($recovery, 'applicants contains rows');
        $this->schemaDb()->table('applicants')->delete();

        $this->schema()->create('applications', fn (Blueprint $table) => $table->id());
        $this->assertRecoveryAborts($recovery, 'another table of migration 100005 already exists');
        $this->schema()->drop('applications');

        $this->schema()->create('recovery_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants');
        });
        $this->assertRecoveryAborts($recovery, 'another table has a foreign key to applicants');
        $this->schema()->drop('recovery_probes');

        $this->schemaDb()->table('migrations')->insert(['migration' => '2026_10_27_100005_create_applicant_and_application_tables', 'batch' => 3]);
        $this->assertRecoveryAborts($recovery, 'migration 100005 or a later one is already recorded');
        $this->schemaDb()->table('migrations')->where('migration', '2026_10_27_100005_create_applicant_and_application_tables')->delete();

        $this->schemaDb()->table('migrations')->where('migration', self::COMPLETED_PLATFORM_MIGRATIONS[3])->update(['migration' => 'renamed_for_test']);
        $this->assertRecoveryAborts($recovery, 'migrations 100001 to 100004 are not all recorded');
        $this->schemaDb()->table('migrations')->where('migration', 'renamed_for_test')->update(['migration' => self::COMPLETED_PLATFORM_MIGRATIONS[3]]);

        $this->schemaDb()->statement('ALTER TABLE `applicants` DROP CONSTRAINT `chk_applicants_status`');
        $this->assertRecoveryAborts($recovery, 'applicants does not have the expected partial CHECK constraints');
        $this->addOriginalCheck('chk_applicants_status', "status IN ('active', 'suspended', 'archived')");

        $this->assertInterruptedState();
        $this->assertSame($partialApplicants, $this->tableSignature('applicants'));

        // The recovery drops only the empty partial table.
        $tablesBefore = $this->tableNames();
        $this->schemaDb()->unprepared($recovery);
        $this->assertSame(array_values(array_diff($tablesBefore, ['applicants'])), $this->tableNames());
        $this->assertSame(count(self::BASELINE_MIGRATIONS) + 4, $this->schemaDb()->table('migrations')->count());

        // The migrations now finish, recreating applicants exactly, plus its country check.
        $this->migrateAll();
        $this->assertSame(8, $this->schemaDb()->table('migrations')->where('batch', 3)->count());
        foreach (self::PLATFORM_TABLES as $table) {
            $this->assertTrue($this->schema()->hasTable($table), "Missing table {$table}");
        }
        $completeApplicants = $this->tableSignature('applicants');
        $this->assertSame(['chk_applicants_country', 'chk_applicants_status', 'chk_applicants_type'], $completeApplicants['checks']);
        $completeApplicants['checks'] = $partialApplicants['checks'];
        $this->assertSame($partialApplicants, $completeApplicants);
        $this->assertSame($user, (array) $this->schemaDb()->table('users')->find($userId));

        // Rolling back affects only the new batch; the earlier successful batch stays.
        $this->rollbackLatestBatch();
        $this->assertFalse($this->schema()->hasTable('applicants'));
        $this->assertTrue($this->schema()->hasTable('cycle_user_roles'));
        $this->assertSame(count(self::BASELINE_MIGRATIONS) + 4, $this->schemaDb()->table('migrations')->count());
        $this->migrateAll();
        $this->assertSame(count(self::BASELINE_MIGRATIONS) + 12, $this->schemaDb()->table('migrations')->count());
        $this->assertSame($user, (array) $this->schemaDb()->table('users')->find($userId));
    }

    /**
     * Applies the baseline and migrations 100001–100004, then replays what the
     * original migration 100005 did before MariaDB rejected its third check.
     */
    private function reproduceInterruptedInstallation(): int
    {
        $this->wipeDisposableDatabase();
        $this->migrateBaseline();
        $userId = $this->schemaDb()->table('users')->insertGetId([
            'name' => 'Existing Account', 'email' => 'existing.user@example.org', 'password' => 'not-a-real-hash',
            'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-02 09:00:00',
        ]);
        $this->schemaDb()->table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => $userId, 'name' => 'existing-token', 'token' => str_repeat('b', 64),
        ]);
        $this->migrateOnly(self::COMPLETED_PLATFORM_MIGRATIONS);

        $this->schema()->create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name_ar', 255);
            $table->foreignId('representative_user_id')->constrained('users', 'id', 'fk_applicants_representative')->restrictOnDelete();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->char('headquarters_country_code', 2)->nullable();
            $table->string('headquarters_governorate', 100)->nullable();
            $table->string('headquarters_address', 500)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('registration_authority', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->datetimes();

            $table->index(['type', 'status'], 'idx_applicants_type_status');
        });
        $this->addOriginalCheck('chk_applicants_type', "type IN ('individual', 'team', 'initiative', 'official_institution', 'organization')");
        $this->addOriginalCheck('chk_applicants_status', "status IN ('active', 'suspended', 'archived')");

        try {
            $this->addOriginalCheck('chk_applicants_country', "headquarters_country_code IS NULL OR REGEXP_LIKE(headquarters_country_code, '^[A-Z]{2}$', 'c')");
            $this->fail('MariaDB accepted REGEXP_LIKE() in a CHECK constraint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('REGEXP_LIKE', $exception->getMessage());
        }

        return $userId;
    }

    private function assertInterruptedState(): void
    {
        $recorded = $this->schemaDb()->table('migrations')->pluck('migration')->all();
        $this->assertEqualsCanonicalizing([...self::BASELINE_MIGRATIONS, ...self::COMPLETED_PLATFORM_MIGRATIONS], $recorded);

        $this->assertTrue($this->schema()->hasTable('applicants'));
        $this->assertSame(0, $this->schemaDb()->table('applicants')->count());
        $this->assertSame(['chk_applicants_status', 'chk_applicants_type'], $this->tableSignature('applicants')['checks']);
        foreach (self::MISSING_APPLICATION_TABLES as $table) {
            $this->assertFalse($this->schema()->hasTable($table), "{$table} should not exist");
        }
    }

    private function assertRecoveryAborts(string $sql, string $reason): void
    {
        try {
            $this->schemaDb()->unprepared($sql);
            $this->fail("The recovery ran although: {$reason}.");
        } catch (QueryException $exception) {
            $this->assertSame(self::SIGNAL_ERROR, (int) ($exception->errorInfo[1] ?? 0), $exception->getMessage());
            $this->assertStringContainsString("Recovery aborted: {$reason}.", $exception->getMessage());
        }

        $this->assertTrue($this->schema()->hasTable('applicants'), "applicants was dropped although: {$reason}.");
    }

    private function addOriginalCheck(string $name, string $expression): void
    {
        $this->schemaDb()->statement("ALTER TABLE `applicants` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /**
     * The recovery block exactly as documented, without its client DELIMITER lines.
     */
    private function documentedRecoverySql(): string
    {
        $document = (string) file_get_contents(base_path(self::RECOVERY_DOCUMENT));
        preg_match_all('/^DELIMITER \/\/\R(BEGIN NOT ATOMIC\R.*?\REND)\/\/\RDELIMITER ;$/ms', $document, $matches);
        $this->assertCount(1, $matches[1], 'Expected exactly one recovery block in '.self::RECOVERY_DOCUMENT);

        return $matches[1][0];
    }

    /**
     * The documented recovery, pointed at the disposable database instead of production.
     */
    private function recoverySql(): string
    {
        $sql = $this->documentedRecoverySql();
        $database = (string) $this->schemaDb()->selectOne('SELECT DATABASE() AS db')->db;
        static::assertSafeDisposableDatabaseName($database);
        $this->assertSame(1, substr_count($sql, self::DOCUMENTED_DATABASE_GUARD));

        return str_replace(self::DOCUMENTED_DATABASE_GUARD, "DATABASE() <> '{$database}'", $sql);
    }

    /**
     * @return array{columns: list<string>, indexes: list<string>, foreign_keys: list<string>, checks: list<string>}
     */
    private function tableSignature(string $table): array
    {
        $rows = fn (string $sql): array => array_map(
            fn (object $row): string => implode(' | ', array_map(fn ($value): string => (string) $value, (array) $row)),
            $this->schemaDb()->select($sql, [$table]),
        );

        return [
            'columns' => $rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'),
            'indexes' => $rows('SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME'),
            'foreign_keys' => $rows('SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME'),
            'checks' => $rows('SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME'),
        ];
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

    private function schema(): Builder
    {
        return Schema::connection(self::SCHEMA_CONNECTION);
    }
}
