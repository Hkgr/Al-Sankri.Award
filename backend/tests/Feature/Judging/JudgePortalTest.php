<?php

namespace Tests\Feature\Judging;

use App\Candidates\AccountContext;
use App\Candidates\Documentation;
use App\Models\ApplicantIndividualDetail;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\ActsAsSpaBrowser;
use Tests\Feature\Candidate\InteractsWithNominationStage;
use Tests\TestCase;

/**
 * The judge portal: an approved committee's members each score every
 * submitted project in their main domain (one mark out of 100, notes,
 * recommendations, and an optional exclusion) and send it to the
 * administration, which shows the average once all of them have sent.
 */
class JudgePortalTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage, RefreshDatabase;

    private const NATIONAL_ID = '04019876543';

    private User $admin;

    private int $cycleId;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('candidate_evidence');
        Notification::fake();
        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'operator@example.org']);
        $this->candidate = User::factory()->candidate()->create(['email' => 'layla@example.org']);
        $this->admin = User::factory()->create(['email' => 'admin@example.org', 'name' => 'مدير الجائزة']);
        $this->configureCycle();
        $this->cycleId = (int) DB::table('award_cycles')->where('code', 'cycle-2026')->value('id');

        $this->publicPath = storage_path('framework/testing/judge-public-'.uniqid());
        File::ensureDirectoryExists($this->publicPath);
        File::put($this->publicPath.'/index.html', '<!doctype html><html lang="ar" dir="rtl"><div id="root"></div></html>');
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_each_judge_sends_one_mark_and_the_admin_sees_the_average(): void
    {
        $submitted = $this->submitApplication();
        $this->assertSame(0, DB::table('judging_assignments')->count());

        $judges = [
            User::factory()->create(['email' => 'huda@example.org', 'name' => 'هدى الخالد']),
            User::factory()->create(['email' => 'sami@example.org', 'name' => 'سامي نعسان']),
            User::factory()->create(['email' => 'reem@example.org', 'name' => 'ريم عدرة']),
        ];
        $this->signInAdmin();
        $this->adminPost('/committees', ['domain' => 'creativity', 'name' => 'لجنة تحكيم الإبداع'])->assertCreated();
        foreach ($judges as $judge) {
            $this->appoint('creativity', $judge->email)->assertCreated();
        }
        $this->approve('creativity')->assertOk();

        $this->assertSame(3, DB::table('judging_assignments')->count());
        $this->assertSame(1, DB::table('evaluation_criteria')->where('code', 'overall_score')->where('max_score', 100)->count());
        $this->assertSame(Application::STATUS_IN_JUDGING, DB::table('applications')->where('id', $submitted['id'])->value('status'));
        $this->adminPost('/committees/creativity/return-to-draft', [])->assertConflict()->assertJsonPath('code', 'judging_history');

        $this->signIn($judges[0]->email);
        $this->navigate('/judge')->assertOk();
        $this->read('/api/judge/projects')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $submitted['id'])
            ->assertJsonPath('data.0.title', 'مبادرة ذاكرة الحيّ')
            ->assertJsonPath('data.0.domain.code', 'creativity')
            ->assertJsonPath('data.0.evaluation.status', 'pending');
        $this->read('/api/judge/projects/'.$submitted['id'])->assertOk()
            ->assertJsonPath('data.nomination_details.title', 'مبادرة ذاكرة الحيّ')
            ->assertJsonMissingPath('data.basic_information');
        $this->assertStringNotContainsString(self::NATIONAL_ID, $this->read('/api/judge/projects/'.$submitted['id'])->getContent());

        $link = (int) DB::table('file_links')->whereNotNull('application_version_id')->value('id');
        $document = $this->browser('GET', '/api/judge/projects/'.$submitted['id'].'/files/'.$link, [
            'account_context' => AccountContext::for($judges[0]),
        ])->assertOk();
        $this->assertStringStartsWith('%PDF', $this->body($document));
        $this->assertStringStartsWith('application/pdf', (string) $document->headers->get('Content-Type'));

        $video = $this->attachVideo($submitted['id']);
        $played = $this->browser('GET', '/api/judge/projects/'.$submitted['id'].'/files/'.$video, [
            'account_context' => AccountContext::for($judges[0]),
        ])->assertOk();
        $this->assertStringStartsWith('video/mp4', (string) $played->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $played->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\x00\x00\x00\x18ftyp", $this->body($played));
        $downloaded = $this->browser('GET', '/api/judge/projects/'.$submitted['id'].'/files/'.$video, [
            'account_context' => AccountContext::for($judges[0]),
            'download' => '1',
        ])->assertOk();
        $this->assertStringStartsWith('video/mp4', (string) $downloaded->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $downloaded->headers->get('Content-Disposition'));
        $this->assertStringContainsString('mp4', (string) $downloaded->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\x00\x00\x00\x18ftyp", $this->body($downloaded));
        Storage::disk(Documentation::DISK)->delete("applications/{$submitted['id']}/judge-video.mp4");

        $this->sendMark($judges[0], $submitted['id'], '80', 'توثيق دقيق', 'يُستكمل التوثيق')->assertOk()
            ->assertJsonPath('data.evaluation.status', 'sent')
            ->assertJsonPath('data.evaluation.score', '80')
            ->assertJsonPath('data.evaluation.recommendations', 'يُستكمل التوثيق')
            ->assertJsonPath('data.evaluation.excluded', false);
        $this->sendMark($judges[0], $submitted['id'], '80', 'مرة ثانية')->assertConflict()->assertJsonPath('code', 'already_sent');
        $this->sendMark($judges[0], $submitted['id'], '101', 'خارج الحد')->assertUnprocessable();

        $this->signIn($judges[1]->email);
        $this->sendMark($judges[1], $submitted['id'], '90.5', 'أثر واضح')->assertOk()->assertJsonPath('data.evaluation.score', '90.5');

        $this->signInAdmin();
        $this->read('/api/admin/applications/'.$submitted['id'])->assertOk()
            ->assertJsonPath('data.judging.ready', true)
            ->assertJsonPath('data.judging.expected', 3)
            ->assertJsonPath('data.judging.received', 2)
            ->assertJsonPath('data.judging.decision', null);

        $this->signIn($judges[2]->email);
        $this->sendMark($judges[2], $submitted['id'], '70', 'يحتاج استكمالاً')->assertOk();

        $this->signInAdmin();
        $this->read('/api/admin/applications/'.$submitted['id'])->assertOk()
            ->assertJsonPath('data.judging.received', 3)
            ->assertJsonPath('data.judging.decision', '80.17')
            ->assertJsonPath('data.judging.scores.0.score', '80')
            ->assertJsonPath('data.judging.scores.0.notes', 'توثيق دقيق')
            ->assertJsonPath('data.judging.scores.0.recommendations', 'يُستكمل التوثيق')
            ->assertJsonPath('data.judging.excluded', false)
            ->assertJsonPath('data.judging.scores.0.excluded', false);

        // The judges' marks export carries the same exclusion and recommendations.
        $export = $this->browser('GET', '/api/admin/exports/judge-scores?format=xlsx&application='.$submitted['id'], headers: [
            'X-Candidate-Context' => AccountContext::for($this->admin),
        ])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'scores');
        file_put_contents($path, $this->body($export));
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($cell): string => (string) $cell->getValue(), $row->getCells());
            }
        }
        $reader->close();
        unlink($path);
        $heading = array_key_first(array_filter($rows, fn (array $row): bool => ($row[2] ?? null) === 'الاستبعاد'));
        $this->assertNotNull($heading);
        $this->assertSame(['هدى الخالد', '80 من 100', 'استُبعد المشارك', 'توثيق دقيق', 'يُستكمل التوثيق'], array_slice($rows[$heading + 1], 0, 5));
        $this->assertSame(['لا', 'لا'], [$rows[$heading + 2][2], $rows[$heading + 3][2]]);

        $this->signIn($this->candidate->email);
        $this->read('/api/judge/projects')->assertForbidden()->assertJsonPath('code', 'not_judge');
        $this->navigate('/judge')->assertForbidden();
    }

    public function test_a_draft_committee_has_nothing_to_score_and_a_later_submission_is_assigned(): void
    {
        $judges = [
            User::factory()->create(['email' => 'huda@example.org', 'name' => 'هدى الخالد']),
            User::factory()->create(['email' => 'sami@example.org', 'name' => 'سامي نعسان']),
            User::factory()->create(['email' => 'reem@example.org', 'name' => 'ريم عدرة']),
        ];
        $this->signInAdmin();
        $this->adminPost('/committees', ['domain' => 'heritage', 'name' => 'لجنة التراث'])->assertCreated();
        foreach ($judges as $judge) {
            $this->appoint('heritage', $judge->email)->assertCreated();
        }
        $this->signIn($judges[0]->email);
        $this->read('/api/judge/projects')->assertOk()->assertJsonCount(0, 'data');

        $this->signInAdmin();
        $this->approve('heritage')->assertOk();
        $submitted = $this->submitApplication(['domain' => 'heritage', 'subcategory' => 'tangible_heritage', 'title' => 'بيت دمشقي']);
        $this->assertSame(3, DB::table('judging_assignments')->where('application_id', $submitted['id'])->count());

        $this->signIn($judges[0]->email);
        $this->read('/api/judge/projects')->assertOk()
            ->assertJsonPath('data.0.title', 'بيت دمشقي')
            ->assertJsonPath('data.0.domain.code', 'heritage');
        $this->browser('POST', '/api/judge/projects/'.$submitted['id'].'/evaluation', [
            'account_context' => 'stale',
            'score' => '10',
            'notes' => 'ملاحظة',
            'recommendations' => 'توصية',
            'excluded' => false,
        ])->assertConflict()->assertJsonPath('code', 'account_mismatch');

        $this->sendMark($judges[0], $submitted['id'], null, 'مخالفة الشروط', 'يُستبعد', true)->assertOk()
            ->assertJsonPath('data.evaluation.score', null)
            ->assertJsonPath('data.evaluation.excluded', true)
            ->assertJsonPath('data.evaluation.notes', 'مخالفة الشروط');
        $this->assertNull(DB::table('evaluation_scores')->value('score'));
        $this->assertSame(1, (int) DB::table('evaluation_scores')->value('is_not_applicable'));

        $this->signIn($judges[1]->email);
        $this->sendMark($judges[1], $submitted['id'], '90', 'علامة')->assertOk();
        $this->signIn($judges[2]->email);
        $this->sendMark($judges[2], $submitted['id'], '70', 'علامة')->assertOk();

        $this->signInAdmin();
        $this->read('/api/admin/applications/'.$submitted['id'])->assertOk()
            ->assertJsonPath('data.judging.received', 3)
            ->assertJsonPath('data.judging.decision', null)
            ->assertJsonPath('data.judging.excluded', true)
            ->assertJsonPath('data.judging.scores.0.score', null)
            ->assertJsonPath('data.judging.scores.0.excluded', true)
            ->assertJsonPath('data.judging.scores.1.score', '90')
            ->assertJsonPath('data.judging.scores.1.excluded', false);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{id: int}
     */
    private function submitApplication(array $details = []): array
    {
        $this->completeStage1($this->candidate);
        $applicantId = (int) DB::table('candidate_profiles')->where('user_id', $this->candidate->id)->value('applicant_id');
        if (! DB::table('applicant_individual_details')->where('applicant_id', $applicantId)->exists()) {
            (new ApplicantIndividualDetail)->forceFill([
                'applicant_id' => $applicantId, 'first_name' => 'ليلى', 'last_name' => 'حسن', 'birth_date' => '1990-04-02',
                'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => self::NATIONAL_ID,
            ])->save();
        }
        $this->signIn($this->candidate->email);
        $this->confirm($details + $this->payload(), $this->journeyKey())->assertCreated();
        $docKey = (string) $this->read('/api/candidate/documentation')->assertOk()->json('journey_key');
        $path = tempnam(sys_get_temp_dir(), 'sa-judge-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".str_repeat(' ', 512));
        $this->travel(3)->seconds();
        $this->browser('POST', '/api/candidate/documentation/files', [
            'kind' => 'explanatory', 'operation_id' => (string) Str::uuid(),
            'account_context' => AccountContext::for($this->candidate), 'journey_key' => $docKey,
        ], files: ['file' => new UploadedFile($path, 'شرح العمل.pdf', null, null, true)])->assertCreated();
        $this->send('/api/candidate/documentation/confirm', ['revision' => 1], $docKey)->assertOk();
        $this->send('/api/candidate/submission', ['reviewed_revision' => 1], $docKey)->assertCreated();

        return ['id' => (int) DB::table('applications')->where('created_by', $this->candidate->id)->value('id')];
    }

    private function signInAdmin(): void
    {
        if (! DB::table('user_roles')->where('user_id', $this->admin->id)->exists()) {
            $this->artisan('admin:grant-access', ['--email' => $this->admin->email])->assertSuccessful();
        }
        $this->signIn($this->admin->email);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function adminPost(string $path, array $payload): TestResponse
    {
        $this->travel(2)->seconds();

        return $this->browser('POST', '/api/admin/cycles/'.$this->cycleId.$path, $payload + [
            'account_context' => AccountContext::for($this->admin),
        ]);
    }

    private function appoint(string $domain, string $email): TestResponse
    {
        $ref = $this->adminPost('/judges/lookup', ['email' => $email])->json('data.account.account_ref');

        return $this->adminPost('/judges', [
            'domain' => $domain, 'email' => $email, 'name' => 'حكَم', 'existing' => true, 'account_ref' => $ref,
        ]);
    }

    private function approve(string $domain): TestResponse
    {
        return $this->adminPost("/committees/{$domain}/approve", []);
    }

    private function body(TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function sendMark(User $judge, int $applicationId, ?string $score, string $notes, string $recommendations = 'توصية', bool $excluded = false): TestResponse
    {
        $payload = [
            'account_context' => AccountContext::for($judge),
            'notes' => $notes,
            'recommendations' => $recommendations,
            'excluded' => $excluded,
        ];
        if ($score !== null) {
            $payload['score'] = $score;
        }

        return $this->browser('POST', "/api/judge/projects/{$applicationId}/evaluation", $payload);
    }

    /**
     * A video linked to the submitted version, with an Arabic name. The
     * bytes are a minimal ftyp box, enough to prove the response is the
     * video and not an error document.
     */
    private function attachVideo(int $applicationId): int
    {
        $versionId = (int) DB::table('applications')->where('id', $applicationId)->value('current_version_id');
        $bytes = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isom";
        $path = "applications/{$applicationId}/judge-video.mp4";
        Storage::disk(Documentation::DISK)->put($path, $bytes);
        $fileId = DB::table('files')->insertGetId([
            'uploaded_by' => $this->candidate->id,
            'storage_disk' => Documentation::DISK,
            'storage_path' => $path,
            'original_name' => 'فيديو العمل.mp4',
            'detected_mime_type' => 'video/mp4',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'scan_status' => 'pending',
            'access_level' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('file_links')->insertGetId([
            'file_id' => $fileId,
            'application_version_id' => $versionId,
            'purpose' => 'documentation_video',
            'display_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
