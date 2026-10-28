<?php

namespace Tests\Feature\Admin;

use App\Admin\SystemLogs;
use App\Candidates\AccountContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * «الأخطاء التقنية»: only the configured Laravel log files of storage/logs,
 * read backwards within a byte budget, returned as allowlisted fields; no
 * message, trace, SQL, link or address ever leaves the server.
 */
class SystemLogsTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    private const REQUEST = '0b6f2f3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    private string $storage;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->storage = storage_path('framework/testing/logs-'.uniqid());
        File::ensureDirectoryExists($this->storage.'/logs');
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.path' => $this->storage.'/logs/laravel.log',
            'logging.channels.daily.days' => 14,
        ]);
        $this->app->useStoragePath($this->storage);

        $this->admin = User::factory()->create(['email' => 'admin@example.org']);
        $this->artisan('admin:grant-access', ['--email' => $this->admin->email])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_entries_are_summarized_without_secrets_and_sources_are_confined(): void
    {
        $exception = '{"userId":5,"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: HY000): SQLSTATE[HY000]: select * from users where password = \'hunter2\' at D:\\\\Website\\\\vendor\\\\laravel\\\\framework\\\\src\\\\Illuminate\\\\Database\\\\Connection.php:825)';
        File::put($this->storage.'/logs/laravel-2026-10-28.log', implode("\n", [
            '[2026-10-28 09:00:00] local.INFO: Mail to layla@example.org: https://award.example/reset-password?token=SECRET123&email=layla {"request_id":"'.self::REQUEST.'"}',
            '[2026-10-28 09:05:00] local.ERROR: SQLSTATE[HY000]: select * from users where password = \'hunter2\' '.$exception,
            '[stacktrace]',
            '#0 D:\\Website\\secret\\path.php(12): run()',
            '#1 {main}',
            '"} {"request_id":"'.self::REQUEST.'"}',
            '',
        ]));
        File::put($this->storage.'/logs/laravel-2026-10-27.log', "[2026-10-27 08:00:00] local.WARNING: something\n");
        File::put($this->storage.'/logs/notes.txt', "[2026-10-28 10:00:00] local.ERROR: not a log source\n");
        File::put($this->storage.'/.env', "APP_KEY=base64:secret\n");

        $this->signIn();
        $page = $this->read('/api/admin/log/system?source=laravel-2026-10-28.log')->assertOk();
        $this->assertSame(['laravel-2026-10-28.log', 'laravel-2026-10-27.log'], array_column($page->json('sources'), 'id'));
        $this->assertSame(14, $page->json('coverage.retention_days'));
        $entries = $page->json('data.entries');
        $this->assertCount(2, $entries, 'the multiline entry is one entry');
        $this->assertSame(['ERROR', 'INFO'], array_column($entries, 'level'), 'newest first');
        $this->assertSame('Illuminate\\Database\\QueryException', $entries[0]['category']);
        $this->assertSame('Connection.php:825', $entries[0]['location']);
        $this->assertSame([self::REQUEST, 5], [$entries[0]['request_id'], $entries[0]['user_id']]);
        $this->assertSame('log_message', $entries[1]['category']);
        $body = (string) $page->getContent();
        foreach (['hunter2', 'SECRET123', 'reset-password', 'layla@example.org', 'SQLSTATE', 'select *', '#0', 'secret', 'Website'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body, $hidden);
        }

        // Only allowlisted sources: no path, no other file, no traversal.
        $this->read('/api/admin/log/system?source=../.env')->assertUnprocessable();
        $this->read('/api/admin/log/system?source=notes.txt')->assertNotFound();
        $this->read('/api/admin/log/system?source=.env')->assertNotFound();

        $export = $this->read('/api/admin/exports/system-logs?format=xlsx&source=laravel-2026-10-28.log')->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('QueryException', $xml);
        $this->assertStringNotContainsString('hunter2', $xml);
        $this->assertStringNotContainsString('SECRET123', $xml);

        File::delete(File::glob($this->storage.'/logs/laravel-*.log'));
        $this->read('/api/admin/log/system')->assertOk()->assertJsonPath('status', 'unavailable');
    }

    public function test_large_logs_are_read_in_bounded_pages_without_gaps(): void
    {
        $lines = [];
        for ($index = 0; $index < 3000; $index++) {
            $time = now()->subMinutes(3000 - $index)->format('Y-m-d H:i:s');
            $lines[] = "[{$time}] local.ERROR: failure {$index} ".str_repeat('x', 500).' {"exception":"[object] (RuntimeException(code: 0): boom at /app/Job.php:9)"}';
        }
        File::put($this->storage.'/logs/laravel-2026-10-26.log', implode("\n", $lines)."\n");

        $this->signIn();
        $first = $this->read('/api/admin/log/system?source=laravel-2026-10-26.log')->assertOk()->json('data');
        $this->assertCount(SystemLogs::PAGE_ENTRIES, $first['entries']);
        $this->assertLessThanOrEqual(SystemLogs::PAGE_SCAN_BYTES + 65_536, $first['window']['scanned_bytes']);
        $this->assertNotNull($first['next_cursor']);
        $second = $this->read("/api/admin/log/system?source=laravel-2026-10-26.log&cursor={$first['next_cursor']}")->assertOk()->json('data');

        $offsets = [...array_column($first['entries'], 'offset'), ...array_column($second['entries'], 'offset')];
        $this->assertSame($offsets, array_values(array_unique($offsets)), 'no entry twice');
        $sorted = $offsets;
        rsort($sorted);
        $this->assertSame($sorted, $offsets, 'continuing backwards, newest first');
        $this->assertLessThan(min(array_column($first['entries'], 'offset')), max(array_column($second['entries'], 'offset')));
    }

    private function signIn(): void
    {
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $this->admin->email, 'password' => 'password'])->assertOk();
    }

    private function read(string $uri): TestResponse
    {
        return $this->browser('GET', $uri, headers: ['X-Candidate-Context' => AccountContext::for($this->admin)]);
    }
}
