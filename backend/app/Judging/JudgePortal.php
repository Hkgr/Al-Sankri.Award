<?php

namespace App\Judging;

use App\Admin\SubmittedApplications;
use App\Models\FileLink;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The judge portal: the signed-in judge's own assignments in an approved
 * committee. A judge sees the submitted work of their main domain, records
 * one mark out of 100 with notes and recommendations, and may exclude the
 * participant. Sending finalizes that evaluation for the administration.
 * Another judge's mark is never included, and it is not edited again here.
 */
class JudgePortal
{
    public const NOT_ASSIGNED = 'not_assigned';

    public const ALREADY_SENT = 'already_sent';

    public const SENT = 'sent';

    public function __construct(private readonly SubmittedApplications $applications) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $judge): array
    {
        $rows = $this->assignments($judge)->get([
            'applications.id', 'applications.reference_number', 'applications.title',
            'award_domains.code as domain_code', 'cycle_domains.name_ar as domain_name',
            'cycle_domain_subcategories.code as subcategory_code', 'cycle_domain_subcategories.name_ar as subcategory_name',
            'evaluations.status as evaluation_status', 'evaluations.recommendation', 'evaluation_scores.score',
        ]);

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'reference_number' => $row->reference_number,
            'title' => $row->title,
            'domain' => ['code' => $row->domain_code, 'name' => $row->domain_name],
            'subcategory' => $row->subcategory_code === null ? null : ['code' => $row->subcategory_code, 'name' => $row->subcategory_name],
            'evaluation' => [
                'status' => $row->evaluation_status === 'finalized' ? 'sent' : 'pending',
                'score' => $row->evaluation_status === 'finalized' && $row->score !== null ? Scores::present((string) $row->score) : null,
                'excluded' => $row->evaluation_status === 'finalized' && $row->recommendation === 'exclude',
            ],
        ])->all();
    }

    /**
     * The submitted work and this judge's own mark, or null when this judge
     * has no assignment for that application.
     *
     * @return array<string, mixed>|null
     */
    public function show(User $judge, int $applicationId): ?array
    {
        $assignment = $this->assignments($judge)->where('applications.id', $applicationId)->first([
            'judging_assignments.id as assignment_id',
            'evaluations.status as evaluation_status', 'evaluations.overall_comment', 'evaluations.recommendation', 'evaluations.finalized_at',
            'evaluation_scores.score', 'evaluation_scores.comment as recommendations',
        ]);
        if ($assignment === null) {
            return null;
        }

        $detail = $this->applications->show($applicationId);
        if ($detail === null) {
            return null;
        }

        $sent = $assignment->evaluation_status === 'finalized';

        return [
            'summary' => $detail['summary'],
            'nomination_details' => $detail['nomination_details'],
            'documentation' => $detail['documentation'],
            'evaluation' => [
                'status' => $sent ? 'sent' : 'pending',
                'score' => $sent && $assignment->score !== null ? Scores::present((string) $assignment->score) : null,
                'notes' => $sent ? $assignment->overall_comment : null,
                'recommendations' => $sent ? $assignment->recommendations : null,
                'excluded' => $sent && $assignment->recommendation === 'exclude',
                'sent_at' => $sent && $assignment->finalized_at !== null
                    ? Carbon::parse($assignment->finalized_at, 'UTC')->toIso8601ZuluString()
                    : null,
            ],
        ];
    }

    /**
     * A file of the assigned application's submitted version, or null.
     */
    public function file(User $judge, int $applicationId, int $linkId): ?FileLink
    {
        $assigned = $this->assignments($judge)->where('applications.id', $applicationId)->exists();

        return $assigned ? $this->applications->file($applicationId, $linkId) : null;
    }

    /**
     * Finalizes this judge's mark. The score is already validated as 0–100
     * with at most two decimals. Notes and recommendations are non-empty
     * trimmed strings. Exclusion is the judge's own flag, not the award result.
     */
    public function send(User $judge, int $applicationId, string $score, string $notes, string $recommendations, bool $excluded): string
    {
        return DB::transaction(function () use ($judge, $applicationId, $score, $notes, $recommendations, $excluded): string {
            User::query()->whereKey($judge->getKey())->lockForUpdate()->firstOrFail();

            $found = $this->assignments($judge)->where('applications.id', $applicationId)->first([
                'judging_assignments.id', 'evaluation_criteria.id as criterion_id', 'evaluation_criteria.max_score',
            ]);
            if ($found === null) {
                return self::NOT_ASSIGNED;
            }

            $assignment = DB::table('judging_assignments')->where('id', $found->id)->lockForUpdate()->first();
            $existing = DB::table('evaluations')->where('judging_assignment_id', $assignment->id)->first();
            if ($assignment->status !== 'assigned' || ($existing !== null && $existing->status === 'finalized')) {
                return self::ALREADY_SENT;
            }

            $now = now();
            $evaluationId = DB::table('evaluations')->insertGetId([
                'judging_assignment_id' => $assignment->id,
                'rubric_id' => $assignment->rubric_id,
                'status' => 'finalized',
                'overall_comment' => $notes,
                'recommendation' => $excluded ? 'exclude' : null,
                'finalized_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('evaluation_scores')->insert([
                'evaluation_id' => $evaluationId,
                'rubric_id' => $assignment->rubric_id,
                'criterion_id' => $found->criterion_id,
                'criterion_max_score' => $found->max_score,
                'score' => Scores::normalize($score),
                'comment' => $recommendations,
                'is_not_applicable' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('judging_assignments')->where('id', $assignment->id)->update(['status' => 'completed', 'updated_at' => $now]);
            AuditLog::record('evaluation.finalized', (int) $judge->getKey(), 'evaluation', (int) $evaluationId, [
                'judging_assignment_id' => (int) $assignment->id,
                'application_id' => $applicationId,
                'score' => Scores::normalize($score),
                'excluded' => $excluded,
            ], (int) DB::table('applications')->where('id', $applicationId)->value('cycle_id'));

            return self::SENT;
        });
    }

    private function assignments(User $judge)
    {
        $query = DB::table('judging_assignments')
            ->join('committee_members', 'committee_members.id', '=', 'judging_assignments.committee_member_id')
            ->join('committees', 'committees.id', '=', 'judging_assignments.committee_id')
            ->join('applications', 'applications.id', '=', 'judging_assignments.application_id')
            ->join('cycle_domains', 'cycle_domains.id', '=', 'applications.cycle_domain_id')
            ->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->leftJoin('cycle_domain_subcategories', 'cycle_domain_subcategories.id', '=', 'applications.subcategory_id')
            ->join('evaluation_criteria', function ($join): void {
                $join->on('evaluation_criteria.rubric_id', '=', 'judging_assignments.rubric_id')
                    ->where('evaluation_criteria.code', Assignments::CRITERION);
            })
            ->leftJoin('evaluations', 'evaluations.judging_assignment_id', '=', 'judging_assignments.id')
            ->leftJoin('evaluation_scores', 'evaluation_scores.evaluation_id', '=', 'evaluations.id')
            ->where('committee_members.user_id', $judge->getKey())
            ->whereNull('committee_members.left_at')
            ->where('committees.type', 'judging')
            ->where('committees.status', 'approved')
            ->where('judging_assignments.round_number', 1)
            ->whereNotNull('applications.current_version_id')
            ->whereColumn('judging_assignments.application_version_id', 'applications.current_version_id')
            ->orderBy('applications.id');

        return $query;
    }
}
