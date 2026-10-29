<?php

namespace App\Admin;

use App\Judging\Assignments;
use App\Models\Application;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The administrative review gate: a final submission arrives here first,
 * classified `pending`, and goes to judging only when an administrator
 * refers it. The classification (applications.review_*) is separate from
 * the workflow status, from a judge's own exclusion recommendation and from
 * the candidate's stages.
 *
 * - Referral: to the current judging committee of the application's own
 *   cycle and MAIN domain (never a subcategory), which must be approved.
 *   Assignments are provisioned in the same transaction, through
 *   App\Judging\Assignments.
 * - Exclusion: archived with a concise reason (administration only); no
 *   assignment. The application, its submitted version and its files stay
 *   available to administration and to their owner.
 *
 * A referral is accepted only for an application provisioning can judge
 * (Assignments::judgeable: not withdrawn or closed), checked on the locked
 * row, and only if every current member then has an assignment for the
 * current version; otherwise the transaction rolls back and nothing is
 * recorded.
 *
 * Both are initial decisions on a pending application. Repeating the same
 * decision returns the stored result without a second event or assignment;
 * the opposite decision is refused (already_decided). Neither depends on
 * the candidate nomination window.
 *
 * Lock order, as committee approval: the administrator's users row
 * (shared), then the committee row, then the application row.
 */
class ApplicationReviews
{
    public const REFERRED = 'referred';

    public const EXCLUDED = 'excluded';

    public const UNCHANGED = 'unchanged';

    public const NOT_FOUND = 'not_found';

    public const ALREADY_DECIDED = 'already_decided';

    public const COMMITTEE_MISSING = 'committee_missing';

    public const COMMITTEE_NOT_APPROVED = 'committee_not_approved';

    public const COMMITTEE_CHANGED = 'committee_changed';

    /** Withdrawn or closed: shown and kept, but never judged (Assignments::judgeable). */
    public const NOT_ELIGIBLE = 'not_eligible';

    /** The assignments could not all be created; nothing was recorded. */
    public const NOT_DELIVERED = 'referral_not_delivered';

    public const REASON_MAX = 500;

    public const LABELS = [
        Application::REVIEW_PENDING => 'لم يُصنّف بعد',
        Application::REVIEW_REFERRED => 'مرسل للتحكيم',
        Application::REVIEW_EXCLUDED => 'مستبعد — مؤرشف',
    ];

    /**
     * The classification as administration sees it, with, for a pending
     * application, the committee a referral would reach or why none can.
     *
     * @return array<string, mixed>
     */
    public function state(int $applicationId): array
    {
        $application = DB::table('applications')
            ->leftJoin('users as deciders', 'deciders.id', '=', 'applications.review_decided_by')
            ->leftJoin('committees', 'committees.id', '=', 'applications.review_committee_id')
            ->where('applications.id', $applicationId)
            ->first([
                'applications.cycle_id', 'applications.cycle_domain_id', 'applications.status', 'applications.current_version_id',
                'applications.review_status', 'applications.review_source',
                'applications.review_decided_at', 'applications.review_reason', 'applications.review_committee_id',
                'deciders.name as decider_name', 'committees.name_ar as committee_name',
            ]);
        $status = $application->review_status ?? Application::REVIEW_PENDING;

        $state = [
            'status' => $status,
            'label' => self::LABELS[$status],
            'legacy' => $application->review_source === Application::REVIEW_LEGACY,
            'decided_at' => $application->review_decided_at === null ? null : Carbon::parse($application->review_decided_at, 'UTC')->toIso8601ZuluString(),
            'decided_by' => $application->decider_name,
            'reason' => $application->review_reason,
            'committee' => $application->review_committee_id === null ? null : ['id' => (int) $application->review_committee_id, 'name' => $application->committee_name],
            'target' => null,
        ];

        if ($status === Application::REVIEW_PENDING) {
            $committee = $this->committee((int) $application->cycle_id, (int) $application->cycle_domain_id);
            $state['target'] = [
                'committee' => $committee === null ? null : ['id' => (int) $committee->id, 'name' => $committee->name_ar, 'approved' => $committee->status === 'approved'],
                'refusal' => ! Assignments::judgeable($application) ? self::NOT_ELIGIBLE
                    : ($committee === null ? self::COMMITTEE_MISSING : ($committee->status === 'approved' ? null : self::COMMITTEE_NOT_APPROVED)),
            ];
        }

        return $state;
    }

    /**
     * Refers a pending application to its committee. The committee id the
     * administrator confirmed must still be the destination.
     */
    public function refer(User $actor, int $applicationId, int $confirmedCommitteeId): string
    {
        $found = $this->submitted($applicationId);
        if ($found === null) {
            return self::NOT_FOUND;
        }

        try {
            return DB::transaction(fn (): string => $this->referLocked($actor, $found, $confirmedCommitteeId));
        } catch (ReferralNotDelivered) {
            return self::NOT_DELIVERED;
        }
    }

    private function referLocked(User $actor, object $found, int $confirmedCommitteeId): string
    {
        DB::table('users')->where('id', $actor->getKey())->sharedLock()->first();
        $committee = $this->committee((int) $found->cycle_id, (int) $found->cycle_domain_id, lock: true);
        $application = DB::table('applications')->where('id', $found->id)->lockForUpdate()->first();

        if ($application->review_status === Application::REVIEW_REFERRED) {
            return self::UNCHANGED;
        }
        if ($application->review_status === Application::REVIEW_EXCLUDED) {
            return self::ALREADY_DECIDED;
        }
        // The same rule provisioning applies, on the locked row.
        if (! Assignments::judgeable($application)) {
            return self::NOT_ELIGIBLE;
        }
        if ($committee === null) {
            return self::COMMITTEE_MISSING;
        }
        if ($committee->status !== 'approved' || $committee->approved_by === null) {
            return self::COMMITTEE_NOT_APPROVED;
        }
        if ((int) $committee->id !== $confirmedCommitteeId) {
            return self::COMMITTEE_CHANGED;
        }

        DB::table('applications')->where('id', $application->id)->update([
            'review_status' => Application::REVIEW_REFERRED,
            'review_source' => Application::REVIEW_BY_ADMIN,
            'review_decided_by' => $actor->getKey(),
            'review_decided_at' => now(),
            'review_committee_id' => $committee->id,
            'updated_at' => now(),
        ]);
        AuditLog::record('application.referred', (int) $actor->getKey(), 'application', (int) $application->id, [
            'committee_id' => (int) $committee->id,
            'domain' => DB::table('cycle_domains')->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
                ->where('cycle_domains.id', $application->cycle_domain_id)->value('award_domains.code'),
        ], (int) $application->cycle_id);
        app(Assignments::class)->provisionReferral((int) $application->id, (int) $actor->getKey());
        if (! app(Assignments::class)->delivered((int) $application->id, (int) $committee->id)) {
            throw new ReferralNotDelivered("Application {$application->id} was not assigned to every member of committee {$committee->id}.");
        }

        return self::REFERRED;
    }

    /**
     * Excludes and archives a pending application, with its reason.
     */
    public function exclude(User $actor, int $applicationId, string $reason): string
    {
        $found = $this->submitted($applicationId);
        if ($found === null) {
            return self::NOT_FOUND;
        }

        return DB::transaction(function () use ($actor, $found, $reason): string {
            DB::table('users')->where('id', $actor->getKey())->sharedLock()->first();
            $application = DB::table('applications')->where('id', $found->id)->lockForUpdate()->first();

            if ($application->review_status === Application::REVIEW_EXCLUDED) {
                return self::UNCHANGED;
            }
            if ($application->review_status === Application::REVIEW_REFERRED) {
                return self::ALREADY_DECIDED;
            }

            DB::table('applications')->where('id', $application->id)->update([
                'review_status' => Application::REVIEW_EXCLUDED,
                'review_source' => Application::REVIEW_BY_ADMIN,
                'review_decided_by' => $actor->getKey(),
                'review_decided_at' => now(),
                'review_reason' => $reason,
                'updated_at' => now(),
            ]);
            // The reason stays on the application; the event records the decision only.
            AuditLog::record('application.excluded', (int) $actor->getKey(), 'application', (int) $application->id, [
                'reference_number' => $application->reference_number,
            ], (int) $application->cycle_id);

            return self::EXCLUDED;
        });
    }

    private function submitted(int $applicationId): ?object
    {
        return DB::table('applications')->where('id', $applicationId)->whereNotNull('current_version_id')
            ->whereIn('status', SubmittedApplications::STATUSES)->first(['id', 'cycle_id', 'cycle_domain_id']);
    }

    /**
     * The current judging committee of a cycle's main domain (as «لجان الحكام»
     * shows it), if any.
     */
    private function committee(int $cycleId, int $cycleDomainId, bool $lock = false): ?object
    {
        return DB::table('committees')->where('cycle_id', $cycleId)->where('cycle_domain_id', $cycleDomainId)
            ->where('type', 'judging')->where('status', '<>', 'dissolved')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }
}
