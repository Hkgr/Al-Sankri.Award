<?php

namespace App\Candidates;

use App\Auth\CandidateRole;
use App\Jobs\DeliverProgressEmail;
use App\Mail\CandidateProgressEmail;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Durable, ordered intent; queue transport and SMTP are both recoverable independently. */
class ProgressNotifications
{
    public const TERMINAL = ['sent', 'suppressed'];

    public const MAX_ATTEMPTS = 8;

    public function intend(int $eventId, Application $application): void
    {
        $recipient = $this->recipient((int) $application->id);
        if ($recipient === null) {
            return;
        }
        $user = User::withTrashed()->find($recipient);
        $id = DB::table('application_progress_notifications')->insertGetId([
            'event_id' => $eventId, 'recipient_user_id' => $recipient,
            'locale' => $user?->preferred_locale === 'en' ? 'en' : 'ar', 'status' => 'pending',
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->enqueue($id));
    }

    public function recipient(int $applicationId): ?int
    {
        $id = DB::table('candidate_nominations')->where('application_id', $applicationId)->value('user_id')
            ?? DB::table('applications')->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
                ->where('applications.id', $applicationId)->value('applicants.representative_user_id');

        return $id === null ? null : (int) $id;
    }

    public function enqueue(int $id): void
    {
        try {
            $connection = (string) config('queue.default');
            // A sync queue would send SMTP in the web request. Leave durable intent for an async worker.
            if (! in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                return;
            }
            $claimed = DB::table('application_progress_notifications')->where('id', $id)
                ->whereNotIn('status', self::TERMINAL)->where(function ($query): void {
                    $query->whereNull('last_enqueued_at')->orWhere('last_enqueued_at', '<=', now()->subMinutes(5));
                })->update(['last_enqueued_at' => now()]);
            if ($claimed) {
                DeliverProgressEmail::dispatch($id)->onConnection($connection)->onQueue('candidate-progress')->afterCommit();
            }
        } catch (Throwable) {
            try {
                DB::table('application_progress_notifications')->where('id', $id)->update(['last_enqueued_at' => null, 'last_error_code' => 'queue_unavailable']);
            } catch (Throwable) {
                // The committed intent is still discovered by recovery after the database returns.
            }
        }
    }

    /** Returns a retry delay or null. A lease survives a worker crash; locks exclude concurrent SMTP. */
    public function deliver(int $id): ?int
    {
        $lease = DB::transaction(function () use ($id): ?string {
            $peek = DB::table('application_progress_notifications')->find($id);
            if ($peek === null) {
                return null;
            }
            $user = User::withTrashed()->whereKey($peek->recipient_user_id)->lockForUpdate()->first();
            $notice = DB::table('application_progress_notifications')->where('id', $id)->lockForUpdate()->first();
            if ($notice === null || in_array($notice->status, [...self::TERMINAL, 'failed'], true)) {
                return null;
            }
            if (Carbon::parse($notice->available_at, 'UTC')->isFuture() || ($notice->status === 'processing' && Carbon::parse($notice->lease_until, 'UTC')->isFuture())) {
                return null;
            }
            if ($notice->attempts >= $notice->attempt_limit) {
                DB::table('application_progress_notifications')->where('id', $id)->update(['status' => 'failed', 'last_error_code' => 'delivery_uncertain', 'updated_at' => now()]);

                return null;
            }
            $event = DB::table('application_progress_events')->find($notice->event_id);
            $earlier = DB::table('application_progress_notifications as notices')->join('application_progress_events as events', 'events.id', '=', 'notices.event_id')
                ->where('events.application_version_id', $event->application_version_id)->where('events.sequence', '<', $event->sequence)
                ->whereNotIn('notices.status', self::TERMINAL)->exists();
            if ($earlier) {
                DB::table('application_progress_notifications')->where('id', $id)->update(['available_at' => now()->addSeconds(30), 'last_enqueued_at' => null]);

                return null;
            }
            $application = Application::query()->find($event->application_id);
            if (! $user?->isActive() || ! $user->hasVerifiedEmail() || ! app(CandidateRole::class)->isHeldBy($user) || $application === null
                || (int) $application->current_version_id !== (int) $event->application_version_id
                || $this->recipient((int) $application->id) !== (int) $user->id
                || ! DB::table('candidate_nominations')->where('application_id', $application->id)->where('user_id', $user->id)->exists()) {
                DB::table('application_progress_notifications')->where('id', $id)->update(['status' => 'suppressed', 'last_error_code' => 'recipient_unavailable', 'updated_at' => now()]);

                return null;
            }
            $token = (string) Str::uuid();
            DB::table('application_progress_notifications')->where('id', $id)->update([
                'status' => 'processing', 'lease_token' => $token, 'lease_until' => now()->addMinutes(3),
                'attempts' => $notice->attempts + 1, 'updated_at' => now(),
            ]);

            return $token;
        }, 3);
        if ($lease === null) {
            return null;
        }
        try {
            // Do not retry this transaction automatically: SMTP acceptance is outside database atomicity.
            DB::transaction(function () use ($id, $lease): void {
                $peek = DB::table('application_progress_notifications')->find($id);
                $user = User::withTrashed()->whereKey($peek?->recipient_user_id)->lockForUpdate()->first();
                $notice = DB::table('application_progress_notifications')->where('id', $id)->lockForUpdate()->first();
                if ($notice === null || $notice->status !== 'processing' || $notice->lease_token !== $lease) {
                    return;
                }
                $event = DB::table('application_progress_events')->find($notice->event_id);
                $application = Application::query()->find($event->application_id);
                if (! $user?->isActive() || ! $user->hasVerifiedEmail() || ! app(CandidateRole::class)->isHeldBy($user) || $application === null
                    || (int) $application->current_version_id !== (int) $event->application_version_id
                    || $this->recipient((int) $application->id) !== (int) $user->id
                    || ! DB::table('candidate_nominations')->where('application_id', $application->id)->where('user_id', $user->id)->exists()) {
                    DB::table('application_progress_notifications')->where('id', $id)->update(['status' => 'suppressed', 'last_error_code' => 'recipient_unavailable', 'lease_until' => null, 'lease_token' => null]);

                    return;
                }
                Mail::to($user->email, Str::limit(Str::squish($user->name), 80))->send(new CandidateProgressEmail($this->messageData($notice, $event, $application, $user)));
                DB::table('application_progress_notifications')->where('id', $id)->update([
                    'status' => 'sent', 'sent_at' => now(), 'lease_until' => null, 'lease_token' => null, 'last_error_code' => null, 'updated_at' => now(),
                ]);
            });
        } catch (Throwable) {
            $notice = DB::table('application_progress_notifications')->find($id);
            $batchAttempt = max(1, $notice->attempts - ($notice->attempt_limit - self::MAX_ATTEMPTS));
            $delay = min(3600, 30 * (2 ** min(7, $batchAttempt - 1)));
            DB::table('application_progress_notifications')->where('id', $id)->where('lease_token', $lease)->update([
                'status' => $notice->attempts >= $notice->attempt_limit ? 'failed' : 'pending', 'available_at' => now()->addSeconds($delay),
                'lease_token' => null, 'lease_until' => null, 'last_enqueued_at' => null, 'last_error_code' => 'delivery_uncertain', 'updated_at' => now(),
            ]);

            return $notice->attempts >= $notice->attempt_limit ? null : $delay;
        }

        return null;
    }

    /** Explicit email allowlist, tied to this transition's time rather than the file's later state. */
    public function messageData(object $notice, object $event, Application $application, User $user): array
    {
        $journey = DB::table('candidate_nominations')->where('application_id', $application->id)->where('user_id', $user->id)->first();
        $profile = $journey === null ? null : CandidateProfile::query()->find($journey->candidate_profile_id);
        $key = $profile === null ? null : app(NominationCycles::class)->journeyKey($profile, (int) $event->cycle_id);
        $version = DB::table('application_versions')->find($event->application_version_id);
        $url = url('/dashboard').'?'.http_build_query(['journey_key' => $key, 'version' => (int) $version->version_number]).'#application-progress';

        return ProgressMessages::for($event->stage, $notice->locale) + [
            'locale' => $notice->locale, 'name' => Str::limit(Str::squish($user->name), 80), 'reference' => $application->reference_number,
            'version' => (int) $version->version_number, 'occurredAt' => $event->occurred_at,
            'actionUrl' => $url, 'messageKey' => 'progress-'.$notice->id.'-'.substr(hash('sha256', (string) config('app.key')), 0, 12),
        ];
    }
}
