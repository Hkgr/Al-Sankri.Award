<?php

namespace Tests;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Runs schema tests against an explicitly named, disposable MySQL database.
 *
 * Opt in with MIGRATION_TEST_DB_DATABASE (plus optional MIGRATION_TEST_DB_HOST,
 * _PORT, _USERNAME, _PASSWORD, which default to the DB_* values). The name must
 * end in "_schema_test", and the live connection is re-checked before anything
 * destructive runs. Without the variable these tests are skipped.
 */
trait UsesDisposableSchemaDatabase
{
    protected const SCHEMA_CONNECTION = 'schema_test';

    /**
     * Migrations that already existed on the develop baseline.
     *
     * @var list<string>
     */
    protected const BASELINE_MIGRATIONS = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
        '2026_10_26_133059_create_personal_access_tokens_table',
    ];

    /**
     * Tables added by the platform database foundation (users is extended, not created).
     *
     * @var list<string>
     */
    protected const PLATFORM_TABLES = [
        'roles', 'permissions', 'role_permissions', 'user_roles', 'cycle_user_roles',
        'award_cycles', 'cycle_stages', 'award_domains', 'cycle_domains', 'award_slots',
        'applicants', 'applicant_members', 'applications', 'application_locations', 'application_versions',
        'files', 'file_links', 'policy_versions', 'application_consents',
        'eligibility_reviews', 'completion_requests',
        'committees', 'committee_members', 'evaluation_rubrics', 'evaluation_criteria',
        'judging_assignments', 'conflict_declarations', 'evaluations', 'evaluation_scores',
        'appeals', 'appeal_decisions',
        'award_results', 'result_ratifications',
        'published_stories', 'audit_logs',
    ];

    protected function configureDisposableDatabase(): void
    {
        $database = (string) env('MIGRATION_TEST_DB_DATABASE', '');

        if ($database === '') {
            $this->markTestSkipped('Set MIGRATION_TEST_DB_DATABASE to a disposable MySQL database ending in "_schema_test".');
        }

        static::assertSafeDisposableDatabaseName($database);

        config(['database.connections.'.self::SCHEMA_CONNECTION => [
            'driver' => 'mysql',
            'host' => env('MIGRATION_TEST_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('MIGRATION_TEST_DB_PORT', env('DB_PORT', '3306')),
            'database' => $database,
            'username' => env('MIGRATION_TEST_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('MIGRATION_TEST_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
        ]]);

        $connection = $this->schemaDb();
        $connected = $connection->selectOne('SELECT DATABASE() AS db, VERSION() AS version');

        if ($connected->db !== $database) {
            throw new RuntimeException("Connected to [{$connected->db}] instead of [{$database}].");
        }

        if (str_contains(strtolower($connected->version), 'mariadb') || version_compare($connected->version, '8.0.16', '<')) {
            $this->markTestSkipped("MySQL 8.0.16+ is required for CHECK constraints; found {$connected->version}.");
        }
    }

    /**
     * Refuses any database name that is not clearly a disposable test database.
     */
    protected static function assertSafeDisposableDatabaseName(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]+_schema_test$/', $database) !== 1) {
            throw new RuntimeException("Refusing to use [{$database}]: disposable schema databases must end in \"_schema_test\".");
        }

        if (in_array($database, [config('database.connections.mysql.database'), env('DB_DATABASE')], true)) {
            throw new RuntimeException("Refusing to use [{$database}]: it is the application's configured database.");
        }
    }

    protected function schemaDb(): ConnectionInterface
    {
        return DB::connection(self::SCHEMA_CONNECTION);
    }

    /**
     * Drops every table in the disposable database, after re-verifying it.
     */
    protected function wipeDisposableDatabase(): void
    {
        $current = $this->schemaDb()->selectOne('SELECT DATABASE() AS db')->db;
        static::assertSafeDisposableDatabaseName((string) $current);

        Schema::connection(self::SCHEMA_CONNECTION)->dropAllTables();
    }

    protected function migrateBaseline(): void
    {
        $paths = array_map(fn (string $name): string => "database/migrations/{$name}.php", self::BASELINE_MIGRATIONS);

        $this->runArtisan('migrate', ['--path' => $paths]);
    }

    protected function migrateAll(): void
    {
        $this->runArtisan('migrate');
    }

    protected function rollbackLatestBatch(): void
    {
        $this->runArtisan('migrate:rollback');
    }

    /**
     * Brings the disposable database to the fully migrated schema if needed.
     */
    protected function ensurePlatformSchema(): void
    {
        $schema = Schema::connection(self::SCHEMA_CONNECTION);

        if ($schema->hasTable('migrations') && $schema->hasTable('audit_logs')
            && $this->schemaDb()->table('migrations')->where('migration', 'like', '2026_10_27_1000%')->count() === 12) {
            return;
        }

        $this->wipeDisposableDatabase();
        $this->migrateBaseline();
        $this->migrateAll();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function runArtisan(string $command, array $options = []): void
    {
        $exitCode = Artisan::call($command, ['--database' => self::SCHEMA_CONNECTION, '--no-interaction' => true] + $options);

        if ($exitCode !== 0) {
            throw new RuntimeException("artisan {$command} failed:\n".Artisan::output());
        }
    }

    /**
     * Asserts that the callback fails with the given MySQL error number.
     *
     * 1062 duplicate key, 1451 parent row in use, 1452 missing parent row,
     * 3819 CHECK constraint violated.
     */
    protected function assertDatabaseRejects(int $mysqlErrorNumber, callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            $this->assertSame($mysqlErrorNumber, (int) ($exception->errorInfo[1] ?? 0), $message.' '.$exception->getMessage());

            return;
        }

        $this->fail("Expected MySQL error {$mysqlErrorNumber}, but the statement succeeded. {$message}");
    }
}
