<?php

namespace Tests\Feature\Candidate;

use App\Admin\ApplicationReviews;
use App\Candidates\ApplicationProgress;
use App\Candidates\Documentation;
use App\Judging\JudgePortal;
use App\Models\ApplicantIndividualDetail;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

class ProgressInitializationTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithProgress, RefreshDatabase;

    private const HISTORICAL_AT = '2026-11-02 14:34:28';

    private const HISTORICAL_ISO = '2026-11-02T14:34:28Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('UTC', config('app.timezone'));
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $modes = explode(',', DB::selectOne('SELECT @@SESSION.sql_mode AS modes')->modes);
            $this->assertContains('STRICT_TRANS_TABLES', $modes);
            $this->assertContains('NO_ZERO_DATE', $modes);
            DB::statement("SET time_zone = '+00:00'");
        }
    }

    public static function latestEvidence(): array
    {
        return ['evidence is later' => [false], 'approval is later' => [true]];
    }

    #[DataProvider('latestEvidence')]
    public function test_actual_command_initializes_a_legacy_referred_file_from_the_latest_evidence(bool $approvalIsLater): void
    {
        $this->prepareProgress();
        $this->prepareProgressCommittee();
        $this->refer($this->progressApplicationId);
        DB::table('judging_assignments')->where('application_id', $this->progressApplicationId)
            ->update(['assigned_at' => $approvalIsLater ? '2026-11-01 10:00:00' : self::HISTORICAL_AT]);
        DB::table('committees')->where('id', $this->progressCommitteeId)
            ->update(['approved_at' => $approvalIsLater ? self::HISTORICAL_AT : '2026-11-01 10:00:00']);

        $this->initializeLegacyFile(ApplicationProgress::COMMITTEE);
    }

    #[DataProvider('latestEvidence')]
    public function test_actual_command_initializes_completed_judging_from_the_last_required_evidence(bool $approvalIsLater): void
    {
        $this->prepareProgress();
        $judges = $this->prepareProgressCommittee();
        $this->refer($this->progressApplicationId);
        foreach ($judges as $index => $judge) {
            $at = $index === count($judges) - 1 && ! $approvalIsLater ? self::HISTORICAL_AT : '2026-11-01 10:00:0'.$index;
            $this->assertSame(JudgePortal::SENT, app(JudgePortal::class)->send(
                $judge, $this->progressApplicationId, '73.00', 'INTERNAL-NOTE', 'INTERNAL-RECOMMENDATION', false,
            ));
            DB::table('evaluations')->where('id', DB::table('evaluations')->max('id'))->update(['finalized_at' => $at]);
        }
        DB::table('committees')->where('id', $this->progressCommitteeId)
            ->update(['approved_at' => $approvalIsLater ? self::HISTORICAL_AT : '2026-10-31 10:00:00']);

        $this->initializeLegacyFile(ApplicationProgress::COMPLETE);
    }

    public static function rawDates(): array
    {
        return [
            'UTC ISO from the reported failure' => [self::HISTORICAL_ISO],
            'equivalent explicit offset' => ['2026-11-02T17:34:28+03:00'],
            'column precision is seconds' => ['2026-11-02T17:34:28.987654+03:00'],
        ];
    }

    #[DataProvider('rawDates')]
    public function test_the_central_writer_preserves_the_instant_of_raw_iso_facts(string $rawAt): void
    {
        $this->prepareProgress();
        $this->removeProgress($this->progressApplicationId);
        $before = $this->protectedRows();
        $this->silenceAndAdvance();
        $progress = new class($rawAt) extends ApplicationProgress
        {
            public function __construct(private string $rawAt) {}

            public function facts(Application $application): ?array
            {
                $facts = parent::facts($application);

                return $facts === null ? null : [...$facts, 'at' => $this->rawAt];
            }
        };
        $this->assertSame($rawAt, $progress->facts(Application::query()->findOrFail($this->progressApplicationId))['at']);

        DB::transaction(fn (): ?int => $progress->record($this->progressApplicationId, historical: true));

        $this->assertHistoricalEvent(ApplicationProgress::ADMINISTRATIVE, self::HISTORICAL_AT);
        $this->travelBack();
        $this->read('/api/candidate/progress')->assertOk()->assertJsonPath('data.updated_at', self::HISTORICAL_ISO);
        $this->assertEquals($before, $this->protectedRows());
        $this->assertSilent();
    }

    public function test_paused_initialization_keeps_an_unknown_date_null_without_inventing_completion(): void
    {
        $this->prepareProgress();
        DB::table('applications')->where('id', $this->progressApplicationId)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->removeProgress($this->progressApplicationId);
        $before = $this->protectedRows();
        $this->silenceAndAdvance();

        $this->initialize(['tracking_paused' => 1], apply: true);

        $this->assertHistoricalEvent(ApplicationProgress::PAUSED, null);
        $this->travelBack();
        $response = $this->read('/api/candidate/progress')->assertOk()
            ->assertJsonPath('data.stage', ApplicationProgress::PAUSED)->assertJsonPath('data.updated_at', null);
        $this->assertNotContains(ApplicationProgress::COMPLETE, array_column($response->json('data.steps'), 'code'));
        $this->assertSame(['code' => ApplicationProgress::PAUSED, 'state' => 'current', 'at' => null], $response->json('data.steps.2'));
        $this->assertEquals($before, $this->protectedRows());
        $this->assertSilent();
    }

    public function test_preview_and_resume_preserve_existing_baselines_and_only_fill_missing_files_once(): void
    {
        $this->prepareProgress();
        $this->removeProgress($this->progressApplicationId);
        DB::transaction(fn (): ?int => app(ApplicationProgress::class)->record($this->progressApplicationId, historical: true));
        $existing = $this->events();

        $this->prepareProgressCommittee();
        $referred = $this->additionalSubmittedFile();
        $this->refer($referred);
        DB::table('judging_assignments')->where('application_id', $referred)->update(['assigned_at' => self::HISTORICAL_AT]);
        DB::table('committees')->where('id', $this->progressCommitteeId)->update(['approved_at' => '2026-11-01 10:00:00']);
        $this->removeProgress($referred);
        $paused = $this->additionalSubmittedFile();
        DB::table('applications')->where('id', $paused)->update(['status' => 'closed']);
        $this->removeProgress($paused);
        $before = $this->protectedRows();
        $this->silenceAndAdvance();
        $counts = ['already_initialized' => 1, ApplicationProgress::COMMITTEE => 1, ApplicationProgress::PAUSED => 1];

        $this->initialize($counts);
        $this->assertEquals($existing, $this->events());
        $this->assertEquals($before, $this->protectedRows());
        $this->assertSilent();

        $this->initialize($counts, apply: true);
        $events = $this->events();
        $this->assertCount(3, $events);
        $this->assertEquals($existing[0], $events[0]);
        $this->assertSame(self::HISTORICAL_AT, $events[1]->occurred_at);
        $this->assertNull($events[2]->occurred_at);
        $this->assertSame([ApplicationProgress::ADMINISTRATIVE, ApplicationProgress::COMMITTEE, ApplicationProgress::PAUSED], array_column($events, 'stage'));

        $this->travelTo(now()->addDay());
        $this->initialize(['already_initialized' => count($events)], apply: true);
        $this->initialize(['already_initialized' => count($events)]);
        $this->assertEquals($events, $this->events());
        $this->assertEquals($before, $this->protectedRows());
        $this->assertSilent();
    }

    private function initializeLegacyFile(string $stage): void
    {
        $this->removeProgress($this->progressApplicationId);
        $this->assertSame(self::HISTORICAL_ISO, app(ApplicationProgress::class)->facts(Application::query()->findOrFail($this->progressApplicationId))['at']);
        $before = $this->protectedRows();
        $this->silenceAndAdvance();

        $this->initialize([$stage => 1]);
        $this->assertSame([], $this->events());
        $this->initialize([$stage => 1], apply: true);

        $this->assertHistoricalEvent($stage, self::HISTORICAL_AT);
        $this->travelBack();
        $response = $this->read('/api/candidate/progress')->assertOk()
            ->assertJsonPath('data.stage', $stage)->assertJsonPath('data.updated_at', self::HISTORICAL_ISO);
        $this->assertSame(['stage', 'reference_number', 'cycle', 'version_number', 'updated_at', 'sequence', 'steps'], array_keys($response->json('data')));
        foreach (['INTERNAL-', 'committee_id', 'judge', 'scores', 'decision', 'recommendation', 'review_reason', 'via_committee', '73.00'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertEquals($before, $this->protectedRows());
        $this->assertSilent();
    }

    private function refer(int $applicationId): void
    {
        $this->assertSame(ApplicationReviews::REFERRED, app(ApplicationReviews::class)->refer($this->operator, $applicationId, $this->progressCommitteeId));
        $this->assertSame(3, DB::table('judging_assignments')->where('application_id', $applicationId)->count());
    }

    private function removeProgress(int $applicationId): void
    {
        DB::table('application_progress_events')->where('application_id', $applicationId)->delete();
    }

    private function silenceAndAdvance(): void
    {
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-11 12:37:05', 'UTC'));
    }

    /** @param array<string, int> $counts */
    private function initialize(array $counts, bool $apply = false): void
    {
        $this->artisan('progress:initialize', ['cycle' => 'cycle-2026'] + ($apply ? ['--apply' => true] : []))
            ->expectsOutput(json_encode($counts, JSON_THROW_ON_ERROR))
            ->expectsOutputToContain($apply ? 'Silent baseline applied; no historical notifications.' : 'Preview only; no data changed and no notifications.')
            ->assertSuccessful();
    }

    private function assertHistoricalEvent(string $stage, ?string $occurredAt): void
    {
        $event = DB::table('application_progress_events')->sole();
        $application = Application::query()->findOrFail($this->progressApplicationId);
        $this->assertSame($stage, $event->stage);
        $this->assertTrue((bool) $event->historical);
        $this->assertSame(1, (int) $event->sequence);
        $this->assertSame((int) $application->id, (int) $event->application_id);
        $this->assertSame((int) $application->current_version_id, (int) $event->application_version_id);
        $this->assertSame((int) $application->cycle_id, (int) $event->cycle_id);
        $this->assertSame($occurredAt, $event->occurred_at);
        $this->assertSame('2026-11-11 12:37:05', $event->recorded_at);
    }

    /** @return list<object> */
    private function events(): array
    {
        return DB::table('application_progress_events')->orderBy('id')->get()->all();
    }

    /** @return array<string, list<object>> */
    private function protectedRows(): array
    {
        $rows = [];
        foreach (['applications', 'application_versions', 'application_consents', 'account_policy_acceptances', 'candidate_nominations', 'files', 'file_links', 'committees', 'committee_members', 'judging_assignments', 'evaluations', 'evaluation_scores', 'audit_logs', 'application_progress_notifications', 'jobs', 'failed_jobs'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return $rows;
    }

    private function assertSilent(): void
    {
        $this->assertDatabaseCount('application_progress_notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    private function additionalSubmittedFile(): int
    {
        $candidate = User::factory()->candidate()->create();
        ['applicant_id' => $applicant] = $this->completeStage1($candidate);
        (new ApplicantIndividualDetail)->forceFill(['applicant_id' => $applicant, 'first_name' => 'Test', 'last_name' => 'Owner', 'birth_date' => '1990-04-02', 'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => '04019876543'])->save();
        $this->signIn($candidate->email);
        $this->confirm($this->payload())->assertCreated();
        $id = (int) DB::table('applications')->where('created_by', $candidate->id)->value('id');
        DB::table('candidate_nominations')->where('application_id', $id)->update(['documentation_confirmed_revision' => 0, 'documentation_confirmed_at' => now()]);
        $file = DB::table('files')->insertGetId(['uploaded_by' => $candidate->id, 'storage_disk' => 'candidate_evidence', 'storage_path' => 'progress-'.$candidate->id.'.pdf', 'original_name' => 'work.pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'scan_status' => 'clean', 'scanned_at' => now(), 'access_level' => 'private']);
        DB::table('file_links')->insert(['file_id' => $file, 'application_id' => $id, 'purpose' => Documentation::KINDS['explanatory']['purpose']]);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated();

        return $id;
    }
}
