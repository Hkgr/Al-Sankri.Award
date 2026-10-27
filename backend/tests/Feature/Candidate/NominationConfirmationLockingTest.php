<?php

namespace Tests\Feature\Candidate;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * The Stage 2 confirmation against an operator closing the stage at the
 * same time, on a disposable MySQL or MariaDB database.
 *
 * The operator's change is the real `nomination:setup --close`, run in a
 * separate PHP process with its own database session; the confirmation runs
 * in this process. The interleavings are controlled, not left to chance: a
 * hook on the confirmation's own queries starts or runs the command at the
 * chosen point. Both sessions are genuinely separate (the waiting is a real
 * InnoDB lock wait), but each case exercises one forced ordering, not
 * arbitrary simultaneous load.
 *
 * Data is committed (no wrapping transaction, so the other session can see
 * it): the disposable database is migrated fresh before and after each test.
 * Skipped on SQLite; refused on any database not named *_schema_test.
 */
class NominationConfirmationLockingTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MySQL or MariaDB: run the feature tests against a disposable *_schema_test database.');
        }
        if (file_exists(base_path('bootstrap/cache/config.php'))) {
            $this->markTestSkipped('The configuration is cached: the operator process would not use the test database.');
        }
        $this->assertDisposableDatabase();
        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'operator@example.org']);
        $this->candidate = User::factory()->candidate()->create(['email' => 'layla@example.org']);
    }

    protected function tearDown(): void
    {
        // Leave no committed rows behind for the next test class.
        if ($this->app !== null && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertDisposableDatabase();
            $this->artisan('migrate:fresh')->assertSuccessful();
        }

        parent::tearDown();
    }

    public function test_a_closure_committed_before_the_configuration_locks_refuses_the_confirmation(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        // The confirmation's transaction has begun and read (its snapshot
        // predates the closure); the operator's closure commits before the
        // confirmation takes its configuration locks.
        $closed = false;
        $this->afterConfirmationQuery('candidate_nominations', function () use (&$closed): void {
            $this->operatorProcess(['--close' => true])->mustRun();
            $closed = true;
        });

        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_unavailable');
        $this->assertTrue($closed, 'the operator closed the stage during the confirmation');
        $this->assertSame('closed', DB::table('cycle_stages')->value('status'));
        $this->assertNothingStored();
    }

    public function test_an_operator_change_waits_while_the_confirmation_holds_the_configuration_locks(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        // The confirmation has taken its configuration locks and is checking
        // availability, before any write (so no foreign-key lock is involved
        // yet); the operator's closure starts in its own process and must wait.
        $operator = null;
        $this->afterConfirmationQuery('cycle_domain_subcategories', function () use (&$operator): void {
            $operator = $this->operatorProcess(['--close' => true]);
            $operator->start();
            $this->assertTrue($this->waitForLockWait($operator), 'the operator session waits on the confirmation\'s locks');
            $this->assertTrue($operator->isRunning(), 'the closure has not finished while the confirmation is open');
            $this->assertSame('enabled', DB::connection('observer')->table('cycle_stages')->value('status'));
        });

        $this->confirm($this->payload(), $journeyKey)->assertCreated();

        $this->assertNotNull($operator);
        $operator->wait();
        $this->assertTrue($operator->isSuccessful(), $operator->getErrorOutput().$operator->getOutput());
        $this->assertSame('closed', DB::table('cycle_stages')->value('status'));
        $this->assertSame(['draft', 1], [DB::table('applications')->value('status'), DB::table('candidate_nominations')->count()]);
    }

    public function test_a_confirmed_journey_is_still_returned_after_another_session_closes_the_stage(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();
        $this->confirm($this->payload(), $journeyKey)->assertCreated();

        $this->operatorProcess(['--close' => true])->mustRun();

        $this->confirm($this->payload(), $journeyKey)->assertConflict()
            ->assertJsonPath('code', 'nomination_details_already_confirmed')->assertJsonPath('data.title', 'مبادرة ذاكرة الحيّ');
        $this->reconcile($journeyKey)->assertOk()->assertJsonPath('journey.status', 'confirmed')->assertJsonPath('data.cycle.code', 'cycle-2026');
        $this->assertSame(1, DB::table('applications')->count());
    }

    private function assertDisposableDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (preg_match('/^[A-Za-z0-9_]+_schema_test$/', $database) !== 1) {
            throw new RuntimeException("Refusing to commit test data to [{$database}]: use a disposable *_schema_test database.");
        }
    }

    /**
     * Runs $callback once, right after the confirmation transaction's first
     * query whose SQL contains $fragment.
     */
    private function afterConfirmationQuery(string $fragment, callable $callback): void
    {
        $armed = true;
        $connection = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $query) use (&$armed, $fragment, $callback, $connection): void {
            if ($armed && $query->connectionName === $connection && $query->connection->transactionLevel() > 0 && str_contains($query->sql, $fragment)) {
                $armed = false;
                $callback();
            }
        });
    }

    /**
     * The real operator command in its own PHP process and database session,
     * on the same disposable database as this test.
     *
     * @param  array<string, true>  $options
     */
    private function operatorProcess(array $options): Process
    {
        $name = DB::getDefaultConnection();
        $config = config("database.connections.{$name}");

        return new Process(
            [PHP_BINARY, 'artisan', 'nomination:setup', 'cycle-2026', '--actor='.$this->operator->email, ...array_keys($options), '--no-interaction'],
            base_path(),
            [
                'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'DB_URL' => '',
                'DB_CONNECTION' => $name, 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'],
                'DB_DATABASE' => (string) $config['database'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'],
            ],
            null,
            60,
        );
    }

    /**
     * Waits until another session is in an InnoDB lock wait (seen from a
     * separate observer connection), or the process ends, or 20 seconds pass.
     */
    private function waitForLockWait(Process $process): bool
    {
        config(['database.connections.observer' => config('database.connections.'.DB::getDefaultConnection())]);
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline && $process->isRunning()) {
            $waiting = DB::connection('observer')->selectOne("SELECT COUNT(*) AS waiting FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'");
            if ((int) $waiting->waiting > 0) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}
