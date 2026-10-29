<?php

namespace App\Judging;

use App\Models\Application;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Gives every current member of an approved judging committee one
 * assignment for every application administratively REFERRED to that
 * committee (review_status `referred`, review_committee_id = the
 * committee), under one rubric: a single mark out of 100, aggregated by
 * average.
 *
 * Called when the committee is approved and when an administrator refers an
 * application (App\Admin\ApplicationReviews). Pending and administratively
 * excluded applications are never assigned on either path: the referral
 * gate is applied here, where every path ends. The committee row is locked
 * by the caller, so two paths cannot create the same assignment twice.
 * Nothing is created for a draft committee.
 */
class Assignments
{
    public const CRITERION = 'overall_score';

    /** Workflow statuses that are never judged, whatever the classification. */
    public const UNJUDGED_STATUSES = [Application::STATUS_DRAFT, 'withdrawn', 'closed'];

    /**
     * Whether an application can be judged at all: it has a submitted
     * version and is not a draft, withdrawn or closed. The one rule shared
     * by provisioning and by the referral that precedes it.
     */
    public static function judgeable(object $application): bool
    {
        return $application->current_version_id !== null && ! in_array($application->status, self::UNJUDGED_STATUSES, true);
    }

    /**
     * Whether every current member of the committee has an assignment for
     * the application's current version (round 1).
     */
    public function delivered(int $applicationId, int $committeeId): bool
    {
        $version = DB::table('applications')->where('id', $applicationId)->value('current_version_id');
        $members = DB::table('committee_members')->where('committee_id', $committeeId)->whereNull('left_at')->pluck('id');
        if ($version === null || $members->isEmpty()) {
            return false;
        }

        return DB::table('judging_assignments')->where('application_version_id', $version)->where('committee_id', $committeeId)
            ->where('round_number', 1)->whereIn('committee_member_id', $members)->distinct()->count('committee_member_id') === $members->count();
    }

    /**
     * The committee was just approved (its row is already locked).
     */
    public function provisionCommittee(int $committeeId, int $assignedBy): void
    {
        $committee = DB::table('committees')->where('id', $committeeId)->first();
        if ($committee === null || $committee->type !== 'judging' || $committee->status !== 'approved') {
            return;
        }

        $this->assign($committee, $assignedBy);
    }

    /**
     * An administrator just referred the application to its committee (the
     * committee row is already locked by the caller). Assigns it when that
     * committee is approved.
     */
    public function provisionReferral(int $applicationId, int $assignedBy): void
    {
        $application = DB::table('applications')->where('id', $applicationId)->first();
        if ($application === null || $application->review_status !== Application::REVIEW_REFERRED || $application->review_committee_id === null) {
            return;
        }

        $committee = DB::table('committees')->where('id', $application->review_committee_id)->first();
        if ($committee === null || $committee->type !== 'judging' || $committee->status !== 'approved') {
            return;
        }

        $this->assign($committee, $assignedBy, (int) $application->id);
    }

    private function assign(object $committee, int $assignedBy, ?int $onlyApplicationId = null): void
    {
        $rubricId = $this->rubricId((int) $committee->cycle_domain_id, $assignedBy);
        $members = DB::table('committee_members')
            ->where('committee_id', $committee->id)
            ->whereNull('left_at')
            ->pluck('id');

        $applications = DB::table('applications')
            ->where('cycle_domain_id', $committee->cycle_domain_id)
            ->whereNotNull('current_version_id')
            ->whereNotIn('status', self::UNJUDGED_STATUSES)
            // The administrative referral gate: referred to this committee only.
            ->where('review_status', Application::REVIEW_REFERRED)
            ->where('review_committee_id', $committee->id)
            ->when($onlyApplicationId !== null, fn ($query) => $query->where('id', $onlyApplicationId))
            ->get(['id', 'current_version_id', 'status']);

        $now = now();
        $created = 0;
        foreach ($applications as $application) {
            foreach ($members as $memberId) {
                $exists = DB::table('judging_assignments')
                    ->where('application_version_id', $application->current_version_id)
                    ->where('committee_member_id', $memberId)
                    ->where('round_number', 1)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('judging_assignments')->insert([
                    'application_version_id' => $application->current_version_id,
                    'application_id' => $application->id,
                    'cycle_domain_id' => $committee->cycle_domain_id,
                    'committee_id' => $committee->id,
                    'committee_member_id' => $memberId,
                    'rubric_id' => $rubricId,
                    'round_number' => 1,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => $now,
                    'status' => 'assigned',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }

            if ($application->status === Application::STATUS_SUBMITTED) {
                DB::table('applications')->where('id', $application->id)->update([
                    'status' => Application::STATUS_IN_JUDGING,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($created > 0) {
            AuditLog::record('judging_assignments.provisioned', $assignedBy, 'committee', (int) $committee->id, [
                'created' => $created,
                'rubric_id' => $rubricId,
            ], (int) $committee->cycle_id);
        }
    }

    /**
     * The domain's approved single-mark rubric, created once.
     */
    private function rubricId(int $cycleDomainId, int $approvedBy): int
    {
        $existing = DB::table('evaluation_criteria')
            ->join('evaluation_rubrics', 'evaluation_rubrics.id', '=', 'evaluation_criteria.rubric_id')
            ->where('evaluation_rubrics.cycle_domain_id', $cycleDomainId)
            ->where('evaluation_rubrics.status', 'approved')
            ->where('evaluation_criteria.code', self::CRITERION)
            ->where('evaluation_criteria.max_score', 100)
            ->orderByDesc('evaluation_rubrics.version_number')
            ->value('evaluation_rubrics.id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $version = (int) DB::table('evaluation_rubrics')->where('cycle_domain_id', $cycleDomainId)->max('version_number');
        $now = now();
        $id = DB::table('evaluation_rubrics')->insertGetId([
            'cycle_domain_id' => $cycleDomainId,
            'version_number' => $version + 1,
            'name_ar' => 'علامة التحكيم',
            'aggregation_method' => 'average',
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('evaluation_criteria')->insert([
            'rubric_id' => $id,
            'code' => self::CRITERION,
            'name_ar' => 'العلامة',
            'weight' => '100.0000',
            'max_score' => '100.00',
            'is_required' => true,
            'display_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $id;
    }
}
