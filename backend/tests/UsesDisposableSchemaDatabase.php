<?php

namespace Tests;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Runs schema tests against an explicitly named, disposable MySQL or MariaDB database.
 *
 * Opt in with MIGRATION_TEST_DB_DATABASE (plus optional MIGRATION_TEST_DB_HOST,
 * _PORT, _USERNAME, _PASSWORD, which default to the DB_* values, and
 * MIGRATION_TEST_DB_DRIVER, "mysql" by default or "mariadb"). The name must
 * end in "_schema_test", and the live connection is re-checked before anything
 * destructive runs. Without the variable these tests are skipped.
 */
trait UsesDisposableSchemaDatabase
{
    protected const SCHEMA_CONNECTION = 'schema_test';

    /**
     * Engine error numbers for each kind of rejection the tests expect.
     *
     * @var array<string, array{mysql: int, mariadb: int}>
     */
    protected const DATABASE_ERRORS = [
        'duplicate' => ['mysql' => 1062, 'mariadb' => 1062],
        'parent_in_use' => ['mysql' => 1451, 'mariadb' => 1451],
        'missing_parent' => ['mysql' => 1452, 'mariadb' => 1452],
        'check_failed' => ['mysql' => 3819, 'mariadb' => 4025],
        'too_long' => ['mysql' => 1406, 'mariadb' => 1406],
    ];

    /**
     * The engine behind the disposable connection: "mysql" or "mariadb".
     */
    protected string $schemaEngine = 'mysql';

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

    /**
     * Additive account migrations that follow the platform foundation
     * (passwordless accounts and Google identities).
     *
     * @var list<string>
     */
    protected const ACCOUNT_MIGRATIONS = [
        '2026_10_27_200001_allow_passwordless_users',
        '2026_10_27_200002_create_oauth_identities_table',
    ];

    /**
     * @var list<string>
     */
    protected const ACCOUNT_TABLES = ['oauth_identities'];

    /**
     * Additive candidate migrations: the «مترشح» role, account policies and
     * the stage 1 basic profile.
     *
     * @var list<string>
     */
    protected const CANDIDATE_MIGRATIONS = [
        '2026_10_27_300001_provision_candidate_role',
        '2026_10_27_300002_create_account_policy_tables',
        '2026_10_27_300003_create_candidate_profile_tables',
    ];

    /**
     * Stage 2 migrations: subcategories, the Stage 2 application columns and
     * partnerships, and the candidate journey.
     *
     * @var list<string>
     */
    protected const NOMINATION_MIGRATIONS = [
        '2026_10_27_400001_create_cycle_domain_subcategories_table',
        '2026_10_27_400002_add_nomination_details_to_applications',
        '2026_10_27_400003_create_candidate_nominations_table',
    ];

    /**
     * Tables added by the Stage 2 migrations (applications gets columns only).
     *
     * @var list<string>
     */
    protected const NOMINATION_TABLES = ['cycle_domain_subcategories', 'application_partnerships', 'candidate_nominations'];

    /**
     * Stage 3 and the final submission: documentation links and stage state.
     *
     * @var list<string>
     */
    protected const DOCUMENTATION_MIGRATIONS = ['2026_10_27_500001_add_candidate_documentation'];

    /**
     * @var list<string>
     */
    protected const DOCUMENTATION_TABLES = ['application_documentation_links'];

    /**
     * The administration phase: the award_admin and judge roles and their
     * permissions (rows only, no tables).
     *
     * @var list<string>
     */
    protected const ADMINISTRATION_MIGRATIONS = ['2026_10_28_600001_provision_administration_and_judge_roles', '2026_10_28_700001_add_registered_users_permission', '2026_10_29_800001_add_reports_and_audit_permissions'];

    /**
     * @var list<string>
     */
    protected const CANDIDATE_TABLES = [
        'account_policy_versions', 'account_policy_acceptances', 'candidate_profiles',
        'applicant_individual_details', 'applicant_institution_details', 'applicant_social_links',
    ];

    protected function configureDisposableDatabase(): void
    {
        $database = (string) env('MIGRATION_TEST_DB_DATABASE', '');

        if ($database === '') {
            $this->markTestSkipped('Set MIGRATION_TEST_DB_DATABASE to a disposable MySQL or MariaDB database ending in "_schema_test".');
        }

        static::assertSafeDisposableDatabaseName($database);

        $driver = (string) env('MIGRATION_TEST_DB_DRIVER', 'mysql');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported MIGRATION_TEST_DB_DRIVER [{$driver}]: use mysql or mariadb.");
        }

        config(['database.connections.'.self::SCHEMA_CONNECTION => [
            'driver' => $driver,
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

        $this->schemaEngine = str_contains(strtolower($connected->version), 'mariadb') ? 'mariadb' : 'mysql';
        $minimum = $this->schemaEngine === 'mariadb' ? '10.11' : '8.0.16';

        if (version_compare($connected->version, $minimum, '<')) {
            throw new RuntimeException("The schema requires {$this->schemaEngine} {$minimum}+; found {$connected->version}.");
        }
    }

    protected function isMariaDb(): bool
    {
        return $this->schemaEngine === 'mariadb';
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
        $this->migrateOnly(self::BASELINE_MIGRATIONS);
    }

    protected function migrateAll(): void
    {
        $this->runArtisan('migrate');
    }

    /**
     * @param  list<string>  $migrations  Migration names, without the .php suffix.
     */
    protected function migrateOnly(array $migrations): void
    {
        $paths = array_map(fn (string $name): string => "database/migrations/{$name}.php", $migrations);

        $this->runArtisan('migrate', ['--path' => $paths]);
    }

    /**
     * The twelve platform foundation migrations, in order.
     *
     * @return list<string>
     */
    protected function platformMigrations(): array
    {
        return array_map(
            fn (string $path): string => basename($path, '.php'),
            glob(database_path('migrations/2026_10_27_1000*.php')),
        );
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

        $later = [...self::ACCOUNT_MIGRATIONS, ...self::CANDIDATE_MIGRATIONS, ...self::NOMINATION_MIGRATIONS, ...self::DOCUMENTATION_MIGRATIONS, ...self::ADMINISTRATION_MIGRATIONS];

        if ($schema->hasTable('migrations') && $schema->hasTable('audit_logs') && $schema->hasTable('application_documentation_links')
            && $this->schemaDb()->table('migrations')->where('migration', 'like', '2026_10_27_1000%')->count() === 12
            && $this->schemaDb()->table('migrations')->whereIn('migration', $later)->count() === count($later)) {
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
     * Asserts that the callback fails with the engine's error number for the
     * given kind of rejection (a key of DATABASE_ERRORS).
     */
    protected function assertDatabaseRejects(string $error, callable $callback, string $message = ''): void
    {
        $errorNumber = self::DATABASE_ERRORS[$error][$this->schemaEngine];

        try {
            $callback();
        } catch (QueryException $exception) {
            $this->assertSame($errorNumber, (int) ($exception->errorInfo[1] ?? 0), $message.' '.$exception->getMessage());

            return;
        }

        $this->fail("Expected {$this->schemaEngine} error {$errorNumber} ({$error}), but the statement succeeded. {$message}");
    }
}
