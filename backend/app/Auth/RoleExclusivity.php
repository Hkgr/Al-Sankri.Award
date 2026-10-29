<?php

namespace App\Auth;

use App\Admin\AdminAccess;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * A person holds one of these two classifications, never both.
 *
 * Appointing a candidate as a judge revokes the candidate classification.
 * A current judge who registers as a participant loses every current judge
 * grant and current judging membership, and receives the candidate
 * classification. Ending the last judgeship restores a candidate
 * classification that an appointment had revoked.
 */
class RoleExclusivity
{
    public const SOURCE_PARTICIPANT = 'participant_registration';

    public const SOURCE_JUDGESHIP_ENDED = 'judgeship_ended';

    public const NOT_JUDGE = 'not_judge';

    public const REGISTERED = 'registered';

    public function __construct(
        private readonly CandidateRole $candidates,
        private readonly AdminAccess $access,
    ) {}

    /**
     * Inside the appointment transaction, under the account lock. A person
     * who just became a judge is no longer a candidate.
     */
    public function revokeCandidateForJudgeship(User $judge, User $actor, int $cycleId): void
    {
        $updated = DB::table('user_roles')
            ->where('user_id', $judge->getKey())
            ->where('role_id', $this->candidates->roleId())
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->getKey(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            AuditLog::record('user_role.revoked', (int) $actor->getKey(), 'user', (int) $judge->getKey(), [
                'role' => CandidateRole::CODE,
                'reason' => 'التعيين كمحكّم',
            ], $cycleId);
        }
    }

    /**
     * Inside the membership-end transaction, after this cycle's judge grant
     * is revoked. Restores the candidate classification only when the
     * account is no longer a judge anywhere and an appointment had revoked it.
     */
    public function restoreCandidateAfterJudgeship(User $user): void
    {
        if ($this->access->isJudge((int) $user->getKey()) || $this->candidates->isHeldBy($user)) {
            return;
        }
        if (! DB::table('user_roles')->where('user_id', $user->getKey())->where('role_id', $this->candidates->roleId())->whereNotNull('revoked_at')->exists()) {
            return;
        }

        $this->candidates->grant($user, self::SOURCE_JUDGESHIP_ENDED);
    }

    /**
     * The signed-in judge registers as a participant. Locks the account,
     * then cancels the judgeship and grants the candidate classification.
     */
    public function registerAsParticipant(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $now = now();
            $grants = DB::table('cycle_user_roles')
                ->where('user_id', $locked->getKey())
                ->where('role_id', $this->access->roleId(AdminAccess::JUDGE_ROLE))
                ->whereNull('revoked_at')
                ->where('starts_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->lockForUpdate()
                ->get(['id', 'cycle_id']);

            if ($grants->isEmpty()) {
                return self::NOT_JUDGE;
            }

            $actorId = (int) $locked->getKey();
            DB::table('cycle_user_roles')->whereIn('id', $grants->pluck('id'))->update([
                'revoked_at' => $now,
                'revoked_by' => $actorId,
                'revocation_reason' => 'التسجيل كمشارك',
                'updated_at' => $now,
            ]);
            foreach ($grants as $grant) {
                AuditLog::record('cycle_user_role.revoked', $actorId, 'user', $actorId, [
                    'role' => AdminAccess::JUDGE_ROLE,
                    'cycle_id' => (int) $grant->cycle_id,
                    'reason' => 'التسجيل كمشارك',
                ], (int) $grant->cycle_id);
            }

            $memberships = DB::table('committee_members')
                ->join('committees', 'committees.id', '=', 'committee_members.committee_id')
                ->where('committee_members.user_id', $actorId)
                ->whereNull('committee_members.left_at')
                ->where('committees.type', 'judging')
                ->lockForUpdate()
                ->get(['committee_members.id', 'committee_members.committee_id', 'committees.cycle_id']);
            if ($memberships->isNotEmpty()) {
                DB::table('committee_members')->whereIn('id', $memberships->pluck('id'))->update(['left_at' => $now, 'updated_at' => $now]);
                foreach ($memberships as $membership) {
                    AuditLog::record('committee_member.ended', $actorId, 'committee', (int) $membership->committee_id, [
                        'committee_member_id' => (int) $membership->id,
                        'user_id' => $actorId,
                        'reason' => 'التسجيل كمشارك',
                    ], (int) $membership->cycle_id);
                }
            }

            $this->candidates->grant($locked, self::SOURCE_PARTICIPANT);

            return self::REGISTERED;
        });
    }
}
