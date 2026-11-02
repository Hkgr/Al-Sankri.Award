<?php

namespace App\Judging;

use App\Models\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the administration sees for one submitted application: each current
 * judge's mark, notes and recommendations, or an exclusion. Exclusion means
 * the participant is non-compliant: that judge records no mark. Once every
 * current member has sent, the decision is their average, unless any of
 * them excluded the participant, in which case the result is exclusion.
 *
 * Only an application the administration referred is judged, by the
 * committee it was referred to; for any other, `referred` is false and no
 * judge is listed (they never received it).
 */
class CommitteeDecision
{
    /**
     * @return array{ready: bool, referred: bool, expected: int, received: int, decision: string|null, excluded: bool, scores: list<array{name: string, score: string|null, notes: string|null, recommendations: string|null, excluded: bool, sent_at: string|null}>}
     */
    public function forApplication(Application $application): array
    {
        $referred = $application->review_status === Application::REVIEW_REFERRED && $application->review_committee_id !== null;
        $empty = ['ready' => false, 'referred' => $referred, 'expected' => 0, 'received' => 0, 'decision' => null, 'excluded' => false, 'scores' => []];
        if ($application->current_version_id === null || ! $referred) {
            return $empty;
        }

        $committee = DB::table('committees')
            ->where('id', $application->review_committee_id)
            ->where('cycle_domain_id', $application->cycle_domain_id)
            ->where('type', 'judging')
            ->whereIn('status', ['approved', 'draft'])
            ->first();
        if ($committee === null) {
            return $empty;
        }

        $members = DB::table('committee_members')
            ->join('users', 'users.id', '=', 'committee_members.user_id')
            ->where('committee_members.committee_id', $committee->id)
            ->orderBy('committee_members.id')
            ->get(['committee_members.id', 'committee_members.left_at', 'users.name']);

        $scores = [];
        $marks = [];
        $received = 0;
        $excluded = false;
        foreach ($members as $member) {
            $current = $member->left_at === null;
            $row = DB::table('judging_assignments')
                ->leftJoin('evaluations', 'evaluations.judging_assignment_id', '=', 'judging_assignments.id')
                ->leftJoin('evaluation_scores', 'evaluation_scores.evaluation_id', '=', 'evaluations.id')
                ->where('judging_assignments.committee_member_id', $member->id)
                ->where('judging_assignments.application_version_id', $application->current_version_id)
                ->where('judging_assignments.round_number', 1)
                ->first(['evaluations.status', 'evaluations.overall_comment', 'evaluations.recommendation', 'evaluations.finalized_at', 'evaluation_scores.score', 'evaluation_scores.comment as recommendations']);

            $memberExcluded = $row !== null && $row->status === 'finalized' && $row->recommendation === 'exclude';
            $scored = $row !== null && $row->status === 'finalized' && ! $memberExcluded && $row->score !== null;
            if ($current && ($memberExcluded || $scored)) {
                $received++;
            }
            if ($current && $memberExcluded) {
                $excluded = true;
            }
            if ($current && $scored) {
                $marks[] = (string) $row->score;
            }
            $sent = $memberExcluded || $scored;
            $scores[] = [
                'name' => $member->name,
                'historical' => ! $current,
                'score' => $scored ? Scores::present((string) $row->score) : null,
                'notes' => $sent ? $row->overall_comment : null,
                'recommendations' => $sent ? $row->recommendations : null,
                'excluded' => $memberExcluded,
                'sent_at' => $sent ? Carbon::parse($row->finalized_at, 'UTC')->toIso8601ZuluString() : null,
            ];
        }

        $expected = $members->whereNull('left_at')->count();
        $ready = $committee->status === 'approved' && ! $application->taxonomy_review_required;
        $complete = $ready && in_array($expected, [3, 5], true) && $received === $expected;

        return [
            'ready' => $ready,
            'referred' => true,
            'expected' => $expected,
            'received' => $received,
            'decision' => $complete && ! $excluded ? Scores::average($marks) : null,
            'excluded' => $complete && $excluded,
            'scores' => $scores,
        ];
    }
}
