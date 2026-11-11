<?php

namespace Tests\Feature\Candidate;

use App\Admin\AccountRemoval;
use App\Admin\AdminAccess;
use App\Admin\ApplicationReviews;
use App\Candidates\AccountContext;
use App\Candidates\ApplicationProgress;
use App\Candidates\ProgressMessages;
use App\Candidates\ProgressNotifications;
use App\Jobs\DeliverProgressEmail;
use App\Mail\CandidateProgressEmail;
use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

class ProgressEmailTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithProgress, RefreshDatabase;

    public static function languages(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('languages')]
    public function test_each_event_has_bilingual_html_plain_text_safe_data_and_a_bound_owner_link(string $locale): void
    {
        $this->prepareProgress();
        $this->candidate->forceFill(['name' => '<script>alert(1)</script>', 'preferred_locale' => $locale])->save();
        $notice = DB::table('application_progress_notifications')->sole();
        $notice->locale = $locale;
        $event = DB::table('application_progress_events')->sole();
        $application = Application::query()->findOrFail($this->progressApplicationId);
        foreach ([ApplicationProgress::ADMINISTRATIVE, ApplicationProgress::COMMITTEE, ApplicationProgress::COMPLETE, ApplicationProgress::PAUSED] as $stage) {
            $event->stage = $stage;
            $data = app(ProgressNotifications::class)->messageData($notice, $event, $application, $this->candidate);
            $mail = new CandidateProgressEmail($data);
            $html = $mail->render();
            $text = view('mail.candidate-progress-text', $mail->content()->with)->render();
            $this->assertStringContainsString('lang="'.$locale.'"', $html);
            $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertMatchesRegularExpression('/src="(?:cid:|data:image\/png;base64,)/', $html);
            $this->assertStringContainsString($data['reference'], $html);
            $this->assertStringContainsString($data['reference'], $text);
            $this->assertStringContainsString($data['actionUrl'], $text);
            $this->assertStringContainsString('/dashboard?journey_key=', $data['actionUrl']);
            $this->assertStringContainsString('version=1', $data['actionUrl']);
            $this->assertStringContainsString('#application-progress', $data['actionUrl']);
            $this->assertSame(ProgressMessages::for($stage, $locale)['subject'].' | '.($locale === 'en' ? 'Dr. Abdulkader Sankari Award' : 'جائزة الدكتور عبد القادر سنكري'), $mail->envelope()->subject);
            foreach (['INTERNAL', 'excluded', 'score', 'average', 'judge_id', 'review_reason', 'استبعاد', 'مستبعد', 'فائز'] as $secret) {
                $this->assertStringNotContainsString($secret, $html.$text.$mail->envelope()->subject);
            }
        }
    }

    public function test_delivery_is_ordered_idempotent_and_describes_the_original_event_even_after_progress_advances(): void
    {
        $this->prepareProgress();
        app(ApplicationReviews::class)->exclude($this->operator, $this->progressApplicationId, 'INTERNAL');
        $notices = DB::table('application_progress_notifications')->orderBy('id')->get();
        Mail::fake();
        $sender = app(ProgressNotifications::class);
        $sender->deliver((int) $notices[1]->id);
        Mail::assertNothingSent();
        $sender->deliver((int) $notices[0]->id);
        $sender->deliver((int) $notices[0]->id);
        $this->travel(31)->seconds();
        $sender->deliver((int) $notices[1]->id);
        $sender->deliver((int) $notices[1]->id);
        Mail::assertSentCount(2);
        Mail::assertSent(CandidateProgressEmail::class, fn (CandidateProgressEmail $mail): bool => $mail->hasTo($this->candidate->email) && $mail->details['subject'] === ProgressMessages::for(ApplicationProgress::ADMINISTRATIVE, 'ar')['subject']);
        $this->assertSame(['sent', 'sent'], DB::table('application_progress_notifications')->orderBy('id')->pluck('status')->all());
        $this->assertSame([1, 1], DB::table('application_progress_notifications')->orderBy('id')->pluck('attempts')->all());
    }

    public function test_transport_failure_is_safe_and_retryable_without_changing_the_application(): void
    {
        $this->prepareProgress();
        $before = DB::table('applications')->find($this->progressApplicationId);
        $notice = DB::table('application_progress_notifications')->sole();
        $manager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp password=secret INTERNAL'));
        $delay = app(ProgressNotifications::class)->deliver((int) $notice->id);
        $this->assertSame(30, $delay);
        $failed = DB::table('application_progress_notifications')->find($notice->id);
        $this->assertSame('pending', $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertSame('delivery_uncertain', $failed->last_error_code);
        $this->assertStringNotContainsString('secret', json_encode($failed));
        $this->assertEquals($before, DB::table('applications')->find($this->progressApplicationId));
        $this->travel(31)->seconds();
        Mail::swap($manager);
        Mail::fake();
        app(ProgressNotifications::class)->deliver((int) $notice->id);
        Mail::assertSentCount(1);
        $this->assertSame('sent', DB::table('application_progress_notifications')->find($notice->id)->status);
    }

    public function test_expired_worker_lease_and_queue_recovery_do_not_resend_a_sent_notification(): void
    {
        $this->prepareProgress();
        $notice = DB::table('application_progress_notifications')->sole();
        DB::table('application_progress_notifications')->where('id', $notice->id)->update(['status' => 'processing', 'lease_token' => '00000000-0000-0000-0000-000000000001', 'lease_until' => now()->subMinute(), 'last_enqueued_at' => null]);
        $this->artisan('progress:recover-emails', ['--cycle' => 'cycle-2026'])->assertSuccessful();
        Queue::assertPushed(DeliverProgressEmail::class, fn (DeliverProgressEmail $job): bool => $job->notificationId === (int) $notice->id);
        Mail::fake();
        app(ProgressNotifications::class)->deliver((int) $notice->id);
        app(ProgressNotifications::class)->deliver((int) $notice->id);
        Mail::assertSentCount(1);
        $this->artisan('progress:recover-emails', ['--cycle' => 'missing'])->assertExitCode(2);
    }

    public function test_unavailable_recipients_are_suppressed_and_locale_is_from_the_owner_not_the_operator(): void
    {
        $this->prepareProgress();
        $this->operator->forceFill(['preferred_locale' => 'en'])->save();
        app(ApplicationReviews::class)->exclude($this->operator, $this->progressApplicationId, 'INTERNAL');
        $this->assertSame(['ar', 'ar'], DB::table('application_progress_notifications')->orderBy('id')->pluck('locale')->all());
        $this->candidate->forceFill(['status' => 'disabled', 'disabled_at' => now()])->save();
        Mail::fake();
        foreach (DB::table('application_progress_notifications')->pluck('id') as $id) {
            app(ProgressNotifications::class)->deliver((int) $id);
        }
        Mail::assertNothingSent();
        $this->assertSame(['suppressed', 'suppressed'], DB::table('application_progress_notifications')->orderBy('id')->pluck('status')->all());
    }

    public function test_language_preference_is_validated_authenticated_and_account_bound(): void
    {
        $this->prepareProgress(false);
        $this->browser('POST', '/api/account/language', ['locale' => 'en', 'account_context' => AccountContext::for($this->candidate)])->assertOk();
        $this->assertSame('en', $this->candidate->fresh()->preferred_locale);
        $this->browser('POST', '/api/account/language', ['locale' => 'fr', 'account_context' => AccountContext::for($this->candidate)])->assertUnprocessable();
        $this->browser('POST', '/api/account/language', ['locale' => 'ar', 'account_context' => 'other-account'])->assertConflict();
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated();
        $this->assertSame('en', DB::table('application_progress_notifications')->sole()->locale);
    }

    public function test_queue_outage_leaves_recoverable_intent_and_never_rolls_back_the_committed_state(): void
    {
        $this->prepareProgress();
        $notice = DB::table('application_progress_notifications')->sole();
        DB::table('application_progress_notifications')->where('id', $notice->id)->update(['last_enqueued_at' => null]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable secret'));
        app(ProgressNotifications::class)->enqueue((int) $notice->id);
        $pending = DB::table('application_progress_notifications')->find($notice->id);
        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->last_enqueued_at);
        $this->assertSame('queue_unavailable', $pending->last_error_code);
        $this->assertSame(Application::STATUS_SUBMITTED, Application::query()->find($this->progressApplicationId)->status);
        $this->assertDatabaseCount('application_progress_events', 1);
    }

    public function test_a_new_non_candidate_role_suppresses_delivery_without_changing_the_submission(): void
    {
        $this->prepareProgress();
        $version = DB::table('application_versions')->sole();
        app(AdminAccess::class)->grantAdmin($this->candidate);
        Mail::fake();
        app(ProgressNotifications::class)->deliver((int) DB::table('application_progress_notifications')->sole()->id);
        Mail::assertNothingSent();
        $this->assertSame('suppressed', DB::table('application_progress_notifications')->sole()->status);
        $this->assertEquals($version, DB::table('application_versions')->sole());
    }

    public function test_explicit_retry_preserves_total_attempts_and_gives_an_exhausted_notice_a_new_budget(): void
    {
        $this->prepareProgress();
        $id = (int) DB::table('application_progress_notifications')->sole()->id;
        $manager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->times(8)->andThrow(new RuntimeException('local transport unavailable'));
        for ($attempt = 0; $attempt < 8; $attempt++) {
            app(ProgressNotifications::class)->deliver($id);
            $this->travel(3601)->seconds();
        }
        $this->assertSame('failed', DB::table('application_progress_notifications')->find($id)->status);
        $this->assertSame(8, DB::table('application_progress_notifications')->find($id)->attempts);
        $this->artisan('progress:recover-emails', ['--cycle' => 'cycle-2026', '--retry-failed' => true])->assertSuccessful();
        $this->assertSame(8, DB::table('application_progress_notifications')->find($id)->attempts);
        $this->assertSame(16, DB::table('application_progress_notifications')->find($id)->attempt_limit);
        Mail::swap($manager);
        Mail::fake();
        app(ProgressNotifications::class)->deliver($id);
        Mail::assertSentCount(1);
        $this->assertSame(9, DB::table('application_progress_notifications')->find($id)->attempts);
    }

    public static function recipientRemovalModes(): array
    {
        return [['retain'], ['purge']];
    }

    public function test_inline_and_failover_queue_drivers_never_send_mail_in_the_calling_request(): void
    {
        $this->prepareProgress();
        $id = (int) DB::table('application_progress_notifications')->sole()->id;
        DB::table('application_progress_notifications')->where('id', $id)->update(['last_enqueued_at' => null]);
        Queue::fake();
        Mail::fake();
        foreach (['sync', 'null', 'deferred', 'background', 'failover'] as $driver) {
            config(['queue.default' => 'inline-test', 'queue.connections.inline-test.driver' => $driver]);
            app(ProgressNotifications::class)->enqueue($id);
        }
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertSame('pending', DB::table('application_progress_notifications')->find($id)->status);
        $this->assertNull(DB::table('application_progress_notifications')->find($id)->last_enqueued_at);
    }

    #[DataProvider('recipientRemovalModes')]
    public function test_deleted_owner_and_archive_identities_never_receive_pending_mail(string $mode): void
    {
        $this->prepareProgress();
        $notice = DB::table('application_progress_notifications')->sole();
        $removal = app(AccountRemoval::class);
        $preview = $removal->preview($this->operator, (int) $this->candidate->id);
        $removal->remove($this->operator, (int) $this->candidate->id, ['operation_id' => (string) Str::uuid(), 'mode' => $mode, 'fingerprint' => $preview['fingerprint'], 'confirmation' => $this->candidate->email]);
        Mail::fake();
        app(ProgressNotifications::class)->deliver((int) $notice->id);
        Mail::assertNothingSent();
        if ($mode === 'purge') {
            $this->assertDatabaseCount('application_progress_notifications', 0);
            $this->assertDatabaseCount('application_progress_events', 0);
        } else {
            $stored = DB::table('application_progress_notifications')->find($notice->id);
            $this->assertSame((int) $this->candidate->id, (int) $stored->recipient_user_id);
            $this->assertSame('suppressed', $stored->status);
        }
    }
}
