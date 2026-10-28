<?php

namespace App\Judging;

use App\Models\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the administration sees for one submitted application: each current
 * judge's mark and notes, and the committee decision (their average) once
 * every current member has sent.
 */
class CommitteeDecision
{
    /**
     * @return array{ready: bool, expected: int, received: int, decision: string|null, scores: list<array{name: string, score: string|null, notes: string|null, sent_at: string|null}>}
     */
    public function forApplication(Application $application): array
    {
        $empty = ['ready' => false, 'expected' => 0, 'received' => 0, 'decision' => null, 'scores' => []];
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
        $finalized = [];
        foreach ($members as $member) {
            $row = DB::table('judging_assignments')
                ->leftJoin('evaluations', 'evaluations.judging_assignment_id', '=', 'judging_assignments.id')
                ->leftJoin('evaluation_scores', 'evaluation_scores.evaluation_id', '=', 'evaluations.id')
                ->where('judging_assignments.committee_member_id', $member->id)
                ->where('judging_assignments.application_version_id', $application->current_version_id)
                ->where('judging_assignments.round_number', 1)
                ->first(['evaluations.status', 'evaluations.overall_comment', 'evaluations.finalized_at', 'evaluation_scores.score']);

            $sent = $row !== null && $row->status === 'finalized' && $row->score !== null;
            if ($sent) {
                $finalized[] = (string) $row->score;
            }
            $scores[] = [
                'name' => $member->name,
                'score' => $sent ? Scores::present((string) $row->score) : null,
                'notes' => $sent ? $row->overall_comment : null,
                'sent_at' => $sent ? Carbon::parse($row->finalized_at, 'UTC')->toIso8601ZuluString() : null,
            ];
        }

        $complete = $members->isNotEmpty() && count($finalized) === $members->count();

        return [
            'ready' => true,
            'expected' => $members->count(),
            'received' => count($finalized),
            'decision' => $complete ? Scores::average($finalized) : null,
            'scores' => $scores,
        ];
    }
}
