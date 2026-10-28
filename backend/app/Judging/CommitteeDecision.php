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
 */
class CommitteeDecision
{
    /**
     * @return array{ready: bool, expected: int, received: int, decision: string|null, excluded: bool, scores: list<array{name: string, score: string|null, notes: string|null, recommendations: string|null, excluded: bool, sent_at: string|null}>}
     */
    public function forApplication(Application $application): array
    {
        $empty = ['ready' => false, 'expected' => 0, 'received' => 0, 'decision' => null, 'excluded' => false, 'scores' => []];
        if ($application->current_version_id === null) {
            return $empty;
        }

        $committee = DB::table('committees')
            ->where('cycle_domain_id', $application->cycle_domain_id)
            ->where('type', 'judging')
            ->where('status', 'approved')
            ->first();
        if ($committee === null) {
            return $empty;
        }

        $members = DB::table('committee_members')
            ->join('users', 'users.id', '=', 'committee_members.user_id')
            ->where('committee_members.committee_id', $committee->id)
            ->whereNull('committee_members.left_at')
            ->orderBy('committee_members.id')
            ->get(['committee_members.id', 'users.name']);

        $scores = [];
        $marks = [];
        $received = 0;
        $excluded = false;
        foreach ($members as $member) {
            $row = DB::table('judging_assignments')
                ->leftJoin('evaluations', 'evaluations.judging_assignment_id', '=', 'judging_assignments.id')
                ->leftJoin('evaluation_scores', 'evaluation_scores.evaluation_id', '=', 'evaluations.id')
                ->where('judging_assignments.committee_member_id', $member->id)
                ->where('judging_assignments.application_version_id', $application->current_version_id)
                ->where('judging_assignments.round_number', 1)
                ->first(['evaluations.status', 'evaluations.overall_comment', 'evaluations.recommendation', 'evaluations.finalized_at', 'evaluation_scores.score', 'evaluation_scores.comment as recommendations']);

            $memberExcluded = $row !== null && $row->status === 'finalized' && $row->recommendation === 'exclude';
            $scored = $row !== null && $row->status === 'finalized' && ! $memberExcluded && $row->score !== null;
            if ($memberExcluded || $scored) {
                $received++;
            }
            if ($memberExcluded) {
                $excluded = true;
            }
            if ($scored) {
                $marks[] = (string) $row->score;
            }
            $sent = $memberExcluded || $scored;
            $scores[] = [
                'name' => $member->name,
                'score' => $scored ? Scores::present((string) $row->score) : null,
                'notes' => $sent ? $row->overall_comment : null,
                'recommendations' => $sent ? $row->recommendations : null,
                'excluded' => $memberExcluded,
                'sent_at' => $sent ? Carbon::parse($row->finalized_at, 'UTC')->toIso8601ZuluString() : null,
            ];
        }

        $complete = $members->isNotEmpty() && $received === $members->count();

        return [
            'ready' => true,
            'expected' => $members->count(),
            'received' => $received,
            'decision' => $complete && ! $excluded ? Scores::average($marks) : null,
            'excluded' => $complete && $excluded,
            'scores' => $scores,
        ];
    }
}
