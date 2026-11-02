<?php

namespace Tests\Feature;

use App\Admin\AdminAccess;
use App\Auth\CandidateRole;
use App\Auth\RoleExclusivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Independent InnoDB sessions contend on the same account for grants, endings and repair. */
class CandidateRoleConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB/MySQL on a disposable *_schema_test database.');
        }
        $this->assertDisposableDatabase();
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertDisposableDatabase();
            $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
        }
        parent::tearDown();
    }

    public function test_concurrent_grants_endings_and_repeated_repair_do_not_duplicate_history(): void
    {
        $user = User::factory()->candidate()->create();
        DB::table('roles')->insert(['code' => 'custom_platform', 'name_ar' => 'Custom fixture', 'scope' => 'platform']);
        $workers = [];
        DB::beginTransaction();
        try {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(AdminAccess::class)->grantAdmin($user);
            foreach (['grant', 'grant', 'reconcile'] as $operation) {
                $worker = $this->worker($user, $operation, 'custom_platform');
                $worker->start();
                $workers[] = $worker;
            }
            $this->assertWaiting(3, $workers);
        } finally {
            DB::commit();
        }
        $this->finish($workers);
        $policy = app(RoleExclusivity::class);
        $this->assertFalse($policy->isCandidate($user));
        $this->assertSame(['award_admin', 'custom_platform'], array_column($policy->currentRoles($user), 'code'));
        $this->assertSame(3, DB::table('user_roles')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user_role.granted')->where('after_data', 'like', '%custom_platform%')->count());

        $workers = [];
        DB::beginTransaction();
        try {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ([['revoke', 'award_admin'], ['revoke', 'custom_platform'], ['reconcile', ''], ['reconcile', '']] as [$operation, $code]) {
                $worker = $this->worker($user, $operation, $code);
                $worker->start();
                $workers[] = $worker;
            }
            $this->assertWaiting(4, $workers);
        } finally {
            DB::commit();
        }
        $this->finish($workers);
        $this->assertTrue($policy->isCandidate($user));
        $this->assertSame(['candidate'], array_column($policy->currentRoles($user), 'code'));
        $this->assertSame(2, DB::table('user_roles')->where('user_id', $user->id)->where('role_id', app(CandidateRole::class)->roleId())->count());
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $user->id)->whereNull('revoked_at')->count());
        $before = DB::table('audit_logs')->count();
        $policy->reconcile($user);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    private function worker(User $user, string $operation, string $code): Process
    {
        $name = DB::getDefaultConnection();
        $config = config("database.connections.{$name}");

        return new Process([PHP_BINARY, base_path('tests/Feature/fixtures/role-policy-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/role-policy-no-config.php',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'DB_URL' => '',
            'DB_CONNECTION' => $name, 'DB_HOST' => (string) $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => (string) $config['database'], 'DB_USERNAME' => (string) $config['username'], 'DB_PASSWORD' => (string) $config['password'],
        ], json_encode(['user_id' => $user->id, 'operation' => $operation, 'code' => $code], JSON_THROW_ON_ERROR), 30);
    }

    /** @param list<Process> $workers */
    private function assertWaiting(int $count, array $workers): void
    {
        config(['database.connections.role_observer' => config('database.connections.'.DB::getDefaultConnection())]);
        $deadline = microtime(true) + 15;
        do {
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), $worker->getErrorOutput().$worker->getOutput());
            }
            $waiting = DB::connection('role_observer')->selectOne("SELECT COUNT(*) AS waiting FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'");
            if ((int) $waiting->waiting >= $count) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent sessions did not reach the expected InnoDB lock waits.');
    }

    /** @param list<Process> $workers */
    private function finish(array $workers): void
    {
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        }
    }

    private function assertDisposableDatabase(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+_schema_test$/', DB::connection()->getDatabaseName());
    }
}
