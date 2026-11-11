<?php

namespace Tests\Feature\Candidate;

use App\Admin\AccountRemoval;
use App\Admin\ApplicationReviews;
use App\Candidates\AccountContext;
use App\Candidates\ApplicationProgress;
use App\Jobs\DeliverProgressEmail;
use App\Judging\Assignments;
use App\Judging\JudgePortal;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

class ApplicationProgressTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithProgress, RefreshDatabase;

    public function test_real_submission_is_one_receipt_and_administrative_transition_and_reads_are_inert(): void
    {
        $this->prepareProgress(false);
        $this->read('/api/candidate/progress')->assertOk()->assertJsonPath('data', null);
        $receipt = $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated()->json('data');
        $this->assertSame(['reference_number', 'submitted_at', 'version_number', 'progress'], array_keys($receipt));
        $this->assertSame(ApplicationProgress::ADMINISTRATIVE, $receipt['progress']['stage']);
        $this->assertSame(['completed', 'current', 'waiting', 'waiting'], array_column($receipt['progress']['steps'], 'state'));
        $this->assertDatabaseCount('application_progress_events', 1);
        $this->assertDatabaseCount('application_progress_notifications', 1);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertOk();
        foreach (['overview', 'review', 'documentation', 'progress'] as $path) {
            $response = $this->read('/api/candidate/'.$path)->assertOk();
            $this->assertStringNotContainsString('"processing"', $response->getContent());
        }
        $this->assertDatabaseCount('application_progress_events', 1);
        $this->assertDatabaseCount('application_progress_notifications', 1);
        $this->assertSame(['stage', 'reference_number', 'cycle', 'version_number', 'updated_at', 'sequence', 'steps'], array_keys($receipt['progress']));
    }

    public static function committeeSizes(): array
    {
        return ['three' => [3], 'five' => [5]];
    }

    #[DataProvider('committeeSizes')]
    public function test_completion_needs_every_current_judge_even_when_one_recommends_exclusion(int $count): void
    {
        $this->prepareProgress();
        $judges = $this->prepareProgressCommittee($count);
        $before = DB::table('application_versions')->sole();
        $reviews = app(ApplicationReviews::class);
        $this->assertSame(ApplicationReviews::REFERRED, $reviews->refer($this->operator, $this->progressApplicationId, $this->progressCommitteeId));
        $this->assertSame(ApplicationReviews::UNCHANGED, $reviews->refer($this->operator, $this->progressApplicationId, $this->progressCommitteeId));
        foreach ($judges as $i => $judge) {
            $this->assertSame(JudgePortal::SENT, app(JudgePortal::class)->send($judge, $this->progressApplicationId, $i === 0 ? null : '73.00', 'INTERNAL-NOTE', 'INTERNAL-RECOMMENDATION', $i === 0));
            $expected = $i === $count - 1 ? ApplicationProgress::COMPLETE : ApplicationProgress::COMMITTEE;
            $this->read('/api/candidate/progress')->assertJsonPath('data.stage', $expected);
        }
        $this->assertSame(JudgePortal::ALREADY_SENT, app(JudgePortal::class)->send($judges[0], $this->progressApplicationId, null, 'INTERNAL', 'INTERNAL', true));
        $this->assertDatabaseCount('application_progress_events', 3);
        $this->assertDatabaseCount('application_progress_notifications', 3);
        $this->assertEquals($before, DB::table('application_versions')->sole());
        foreach (['overview', 'review', 'documentation', 'progress'] as $path) {
            $response = $this->read('/api/candidate/'.$path)->assertOk();
            foreach (['INTERNAL-', '"processing"', '"excluded"', '"scores"', '"expected"', '"received"', '"committee_id"', '"decision"', '"review_reason"'] as $secret) {
                if ($secret === '"received"') {
                    continue;
                } // The public receipt step is named received, never a count.
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_administrative_end_is_neutral_and_never_marks_unperformed_judging_complete(): void
    {
        $this->prepareProgress();
        $this->assertSame(ApplicationReviews::EXCLUDED, app(ApplicationReviews::class)->exclude($this->operator, $this->progressApplicationId, 'INTERNAL-EXCLUSION-REASON'));
        $this->assertSame(ApplicationReviews::UNCHANGED, app(ApplicationReviews::class)->exclude($this->operator, $this->progressApplicationId, 'ignored'));
        $response = $this->read('/api/candidate/progress')->assertJsonPath('data.stage', ApplicationProgress::COMPLETE);
        $this->assertSame(['received', ApplicationProgress::ADMINISTRATIVE, ApplicationProgress::COMPLETE], array_column($response->json('data.steps'), 'code'));
        $this->assertStringNotContainsString('excluded', $response->getContent());
        $this->assertStringNotContainsString('INTERNAL', $response->getContent());
        $this->assertDatabaseCount('application_progress_notifications', 2);
        DB::table('applications')->where('id', $this->progressApplicationId)->update(['status' => 'closed']);
        $this->read('/api/candidate/progress')->assertJsonPath('data.stage', ApplicationProgress::COMPLETE);
    }

    public function test_failed_assignment_delivery_and_rollback_create_no_transition_or_intent(): void
    {
        $this->prepareProgress();
        $this->prepareProgressCommittee();
        $queuedBefore = Queue::pushed(DeliverProgressEmail::class)->count();
        $this->app->instance(Assignments::class, Mockery::mock(Assignments::class)->makePartial()->shouldReceive('delivered')->andReturn(false)->getMock());
        $this->assertSame(ApplicationReviews::NOT_DELIVERED, app(ApplicationReviews::class)->refer($this->operator, $this->progressApplicationId, $this->progressCommitteeId));
        $this->assertDatabaseCount('judging_assignments', 0);
        $this->assertSame($queuedBefore, Queue::pushed(DeliverProgressEmail::class)->count());
        $this->assertDatabaseCount('application_progress_events', 1);
        $this->assertDatabaseCount('application_progress_notifications', 1);
        try {
            DB::transaction(function (): void {
                app(ApplicationReviews::class)->exclude($this->operator, $this->progressApplicationId, 'INTERNAL');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('application_progress_events', 1);
        $this->assertDatabaseCount('application_progress_notifications', 1);
    }

    public function test_legacy_reads_and_cycle_scoped_initialization_never_send_historical_mail_or_invent_dates(): void
    {
        $this->prepareProgress();
        DB::table('application_progress_events')->where('application_id', $this->progressApplicationId)->delete();
        $before = DB::table('application_versions')->sole();
        DB::table('applications')->where('id', $this->progressApplicationId)->update(['updated_at' => now()->addYear()]);
        $this->read('/api/candidate/progress')->assertJsonPath('data.stage', ApplicationProgress::ADMINISTRATIVE);
        $this->assertDatabaseCount('application_progress_events', 0);
        $this->artisan('progress:initialize', ['cycle' => 'cycle-2026'])->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertDatabaseCount('application_progress_events', 0);
        $this->artisan('progress:initialize', ['cycle' => 'cycle-2026', '--apply' => true])->assertSuccessful();
        $this->artisan('progress:initialize', ['cycle' => 'cycle-2026', '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('application_progress_events', 1);
        $this->assertDatabaseCount('application_progress_notifications', 0);
        $this->assertTrue((bool) DB::table('application_progress_events')->sole()->historical);
        $this->assertEquals($before, DB::table('application_versions')->sole());
        DB::table('applications')->where('id', $this->progressApplicationId)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->read('/api/candidate/progress')->assertJsonPath('data.stage', ApplicationProgress::PAUSED)->assertJsonPath('data.updated_at', null);
        $this->assertDatabaseCount('application_progress_events', 1);
        $this->artisan('progress:initialize', ['cycle' => 'missing'])->assertExitCode(2);
    }

    public function test_journey_ownership_version_and_cycle_are_checked_even_after_the_nomination_window_closes(): void
    {
        $this->prepareProgress();
        $key = $this->journeyKey();
        DB::table('award_cycles')->where('id', $this->progressCycleId)->update(['status' => 'closed']);
        $this->configureCycle('cycle-2027');
        $url = '/api/candidate/progress/journey?journey_key='.urlencode($key);
        $this->read($url.'&version=1')->assertOk()->assertJsonPath('data.cycle.code', 'cycle-2026');
        $this->read($url.'&version=2')->assertConflict()->assertJsonPath('code', 'progress_version_changed');
        $other = User::factory()->candidate()->create();
        $this->completeStage1($other);
        $this->signIn($other->email);
        $this->read($url)->assertConflict()->assertJsonPath('code', 'journey_changed');
        $this->read('/api/candidate/progress', AccountContext::for($this->candidate))->assertConflict()->assertJsonPath('code', 'account_mismatch');
    }

    public function test_incomplete_legacy_receipt_never_fabricates_completed_steps_or_reopens_the_file(): void
    {
        $this->prepareProgress();
        $legacy = Application::query()->findOrFail($this->progressApplicationId);
        $legacy->reference_number = null;
        $projection = app(ApplicationProgress::class)->forApplication($legacy);
        $this->assertSame(ApplicationProgress::PAUSED, $projection['stage']);
        $this->assertNull($projection['updated_at']);
        $this->assertSame([['code' => ApplicationProgress::PAUSED, 'state' => 'current', 'at' => null]], $projection['steps']);
        $this->read('/api/candidate/review')->assertJsonPath('data.documentation.editable', false);
        $this->assertDatabaseCount('application_progress_events', 1);
    }

    public static function removalModes(): array
    {
        return [['retain'], ['purge']];
    }

    public function test_sign_in_preserves_the_tracking_journey_and_version_without_granting_access_from_the_link(): void
    {
        $this->prepareProgress();
        $target = '/dashboard?journey_key='.urlencode($this->journeyKey()).'&version=1#application-progress';
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('GET', '/api/candidate/progress')->assertUnauthorized();
        $this->browser('POST', '/api/auth/login', ['email' => $this->candidate->email, 'password' => 'password', 'redirect' => $target])
            ->assertOk()->assertJsonPath('redirect', $target);
    }

    #[DataProvider('removalModes')]
    public function test_judge_removal_records_a_neutral_pause_and_preserves_other_members_scores(string $mode): void
    {
        $this->prepareProgress();
        $judges = $this->prepareProgressCommittee();
        app(ApplicationReviews::class)->refer($this->operator, $this->progressApplicationId, $this->progressCommitteeId);
        foreach ($judges as $judge) {
            app(JudgePortal::class)->send($judge, $this->progressApplicationId, '80.00', 'PRIVATE-NOTE', 'PRIVATE-RECOMMENDATION', false);
        }
        $before = DB::table('application_versions')->sole();
        $other = DB::table('evaluations')->join('judging_assignments', 'judging_assignments.id', '=', 'evaluations.judging_assignment_id')
            ->join('committee_members', 'committee_members.id', '=', 'judging_assignments.committee_member_id')
            ->where('committee_members.user_id', $judges[1]->id)->first(['evaluations.*']);
        $removal = app(AccountRemoval::class);
        $preview = $removal->preview($this->operator, (int) $judges[0]->id);
        $removal->remove($this->operator, (int) $judges[0]->id, ['operation_id' => (string) Str::uuid(), 'mode' => $mode, 'fingerprint' => $preview['fingerprint'], 'confirmation' => $judges[0]->email]);
        $this->read('/api/candidate/progress')->assertJsonPath('data.stage', ApplicationProgress::PAUSED);
        $this->assertEquals($other, DB::table('evaluations')->where('id', $other->id)->first());
        $this->assertEquals($before, DB::table('application_versions')->sole());
        $this->assertSame(ApplicationProgress::PAUSED, DB::table('application_progress_events')->orderByDesc('id')->first()->stage);
        $this->assertSame(1, DB::table('application_progress_events')->where('stage', ApplicationProgress::PAUSED)->count());
    }
}
