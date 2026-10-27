<?php

namespace Tests\Feature\Candidate;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
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
 * in this process, or (when it must start second) in its own process through
 * the real confirmation service (fixtures/confirm-nomination.php). The
 * operator is another account, or the candidate's own account. The interleavings are controlled, not left to chance: a
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
            $this->assertTrue($this->waitForLockWaits(1, $operator), 'the operator session waits on the confirmation\'s locks');
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

    /**
     * The candidate's own account as the command's actor. The confirmation
     * holds the candidate's users row; the closure starts before the
     * confirmation takes its configuration locks. The closure's audit row
     * references the same users row, so the two sessions must take the
     * users row and the cycle in the same order.
     */
    public function test_the_candidate_as_actor_waits_for_a_confirmation_that_holds_its_account(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        $operator = null;
        $this->afterConfirmationQuery('candidate_nominations', function () use (&$operator): void {
            $operator = $this->operatorProcess(['--close' => true], $this->candidate->email);
            $operator->start();
            $this->assertTrue($this->waitForLockWaits(1, $operator), 'the closure waits on the confirmation');
            $this->assertSame('enabled', DB::connection('observer')->table('cycle_stages')->value('status'));
        });

        $this->confirm($this->payload(), $journeyKey)->assertCreated();

        $this->assertNotNull($operator);
        $operator->wait();
        $this->assertTrue($operator->isSuccessful(), $operator->getErrorOutput().$operator->getOutput());
        $this->assertSame('closed', DB::table('cycle_stages')->value('status'));
        $this->assertSame([1, 1], [DB::table('applications')->count(), DB::table('candidate_nominations')->count()]);
        $this->assertAudit(['nomination_details.confirmed' => 1, 'cycle_stage.closed' => 1]);
    }

    /**
     * The other order, with the candidate's own account as actor: the
     * closure holds its locks first (kept from finishing by a third session
     * that holds the stage row), then the confirmation starts in its own
     * process. Releasing the stage row lets the closure finish first; the
     * confirmation then sees the stage closed.
     */
    public function test_a_confirmation_waits_for_a_closure_by_the_candidate_that_started_first(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();
        $holder = $this->observer();
        $holder->beginTransaction();

        try {
            $holder->table('cycle_stages')->lockForUpdate()->get();

            $operator = $this->operatorProcess(['--close' => true], $this->candidate->email);
            $operator->start();
            $this->assertTrue($this->waitForLockWaits(1, $operator), 'the closure is under way and waits on the held stage row');

            $confirmation = $this->confirmationProcess($journeyKey);
            $confirmation->start();
            $this->assertTrue($this->waitForLockWaits(2, $operator, $confirmation), 'the confirmation waits too');
        } finally {
            $holder->rollBack();
        }

        $operator->wait();
        $confirmation->wait();
        $this->assertTrue($operator->isSuccessful(), $operator->getErrorOutput().$operator->getOutput());
        $this->assertTrue($confirmation->isSuccessful(), $confirmation->getErrorOutput().$confirmation->getOutput());
        $this->assertSame('nomination_unavailable', json_decode($confirmation->getOutput(), true)['outcome'] ?? null, $confirmation->getOutput());
        $this->assertSame('closed', DB::table('cycle_stages')->value('status'));
        $this->assertNothingStored();
        $this->assertAudit(['nomination_details.confirmed' => 0, 'cycle_stage.closed' => 1]);
    }

    /**
     * The same order with a different operator: the confirmation does not
     * wait on the operator's account, only on the configuration.
     */
    public function test_a_confirmation_in_its_own_process_succeeds_before_a_later_closure(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        $confirmation = $this->confirmationProcess($journeyKey);
        $confirmation->mustRun();
        $this->assertSame('confirmed', json_decode($confirmation->getOutput(), true)['outcome'] ?? null, $confirmation->getOutput());
        $this->operatorProcess(['--close' => true])->mustRun();

        $this->assertSame([1, 'closed'], [DB::table('applications')->count(), DB::table('cycle_stages')->value('status')]);
        $this->assertAudit(['nomination_details.confirmed' => 1, 'cycle_stage.closed' => 1], $this->operator);
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
    private function operatorProcess(array $options, ?string $actor = null): Process
    {
        return new Process(
            [PHP_BINARY, 'artisan', 'nomination:setup', 'cycle-2026', '--actor='.($actor ?? $this->operator->email), ...array_keys($options), '--no-interaction'],
            base_path(),
            $this->processEnvironment(),
            null,
            60,
        );
    }

    /**
     * The real confirmation service (NominationDetails::confirm) for the
     * signed-in candidate, in its own PHP process and database session.
     */
    private function confirmationProcess(string $journeyKey): Process
    {
        return new Process(
            [PHP_BINARY, base_path('tests/Feature/Candidate/fixtures/confirm-nomination.php')],
            base_path(),
            $this->processEnvironment(),
            json_encode(['user_id' => $this->candidate->id, 'journey_key' => $journeyKey, 'data' => $this->payload()], JSON_THROW_ON_ERROR),
            60,
        );
    }

    /**
     * The same disposable database as this test, for another process.
     *
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        $name = DB::getDefaultConnection();
        $config = config("database.connections.{$name}");

        return [
            'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'DB_URL' => '',
            'DB_CONNECTION' => $name, 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => (string) $config['database'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'],
        ];
    }

    /**
     * A separate connection to the same database (observing, or holding a lock).
     */
    private function observer(): ConnectionInterface
    {
        config(['database.connections.observer' => config('database.connections.'.DB::getDefaultConnection())]);

        return DB::connection('observer');
    }

    /**
     * Audit rows per action, all attributed to $actor (the candidate by default).
     *
     * @param  array<string, int>  $expected
     */
    private function assertAudit(array $expected, ?User $actor = null): void
    {
        foreach ($expected as $action => $count) {
            $rows = DB::table('audit_logs')->where('action', $action)->get();
            $this->assertCount($count, $rows, $action);
            foreach ($rows as $row) {
                $expectedActor = $action === 'nomination_details.confirmed' ? $this->candidate : ($actor ?? $this->candidate);
                $this->assertSame((int) $expectedActor->id, (int) $row->actor_user_id, "{$action} attribution");
            }
        }
    }

    /**
     * Waits until at least $count sessions are in an InnoDB lock wait (seen
     * from the observer connection), while every process is still running;
     * false if one ends first or 20 seconds pass.
     */
    private function waitForLockWaits(int $count, Process ...$processes): bool
    {
        $observer = $this->observer();
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    return false;
                }
            }
            $waiting = $observer->selectOne("SELECT COUNT(*) AS waiting FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'");
            if ((int) $waiting->waiting >= $count) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}
