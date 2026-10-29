<?php

namespace App\Auth;

use App\Admin\AdminAccess;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * A person holds one of these two classifications, never both, and only
 * administration changes them:
 * - every public registration starts with the candidate classification;
 * - appointing the account as a judge revokes it, in the appointment's
 *   transaction (account data, profiles, drafts and role history are kept);
 * - ending the last judgeship (an administration action, under the
 *   existing membership rules) restores a candidate classification that an
 *   appointment had revoked.
 *
 * There is no self-service conversion: a judge cannot cancel the judgeship
 * by registering as a participant.
 */
class RoleExclusivity
{
    public const SOURCE_JUDGESHIP_ENDED = 'judgeship_ended';

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
}
