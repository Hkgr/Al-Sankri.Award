<?php

namespace App\Auth;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The platform classification «مترشح» (roles.code = "candidate").
 *
 * Every public account receives it from the backend when it is created
 * (registration, Google sign-up), in the same transaction as the account.
 * It is never accepted from a request, and it does not mean that a
 * nomination was submitted. The role is provisioned by the migration
 * 2026_10_27_300001_provision_candidate_role.
 */
class CandidateRole
{
    public const CODE = 'candidate';

    public const NAME = 'مترشح';

    public const SOURCE_REGISTRATION = 'registration';

    public const SOURCE_GOOGLE = 'google_sign_up';

    public const SOURCE_PROVISIONING = 'provisioning';

    public function roleId(): int
    {
        $id = DB::table('roles')->where('code', self::CODE)->where('scope', 'platform')->value('id');

        if ($id === null) {
            throw new RuntimeException('The candidate role is missing: run "php artisan migrate" to provision it.');
        }

        return (int) $id;
    }

    /**
     * Grants the role as a system assignment, unless the account already
     * holds it. Call inside the transaction that creates the account.
     *
     * @return bool Whether a new grant was recorded.
     */
    public function grant(User $user, string $source): bool
    {
        $roleId = $this->roleId();

        if ($this->holdsRole($user->getKey(), $roleId)) {
            return false;
        }

        $now = now();
        DB::table('user_roles')->insert([
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'assigned_by' => null,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AuditLog::record('user_role.granted', null, 'user', (int) $user->getKey(), ['role' => self::CODE, 'source' => $source]);

        return true;
    }

    public function isHeldBy(User $user): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->getKey())
            ->where('roles.code', self::CODE)
            ->whereNull('user_roles.revoked_at')
            ->exists();
    }

    private function holdsRole(int|string $userId, int $roleId): bool
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->whereNull('revoked_at')
            ->exists();
    }
}
