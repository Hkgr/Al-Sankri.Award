<?php

namespace App\Admin;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who may use the administration dashboard, and who is a judge, read from
 * the existing role tables only (never from a request).
 *
 * - Administration: the permissions of the account's current platform
 *   grants (user_roles, not revoked) through role_permissions. The
 *   `award_admin` role carries both permissions below; it is granted only by
 *   `php artisan admin:grant-access`.
 * - Judge: a current `judge` grant in cycle_user_roles (not revoked, inside
 *   its starts_at/ends_at window), given with a judging-committee membership.
 *
 * Roles and permissions are provisioned by the migration
 * 2026_10_28_600001_provision_administration_and_judge_roles.
 */
class AdminAccess
{
    public const ADMIN_ROLE = 'award_admin';

    public const JUDGE_ROLE = 'judge';

    public const VIEW_APPLICATIONS = 'applications.view_submitted';

    public const MANAGE_COMMITTEES = 'committees.manage_judging';

    public const VIEW_USERS = 'users.view_registered';

    /** The administration permissions this dashboard knows (anything else is ignored). */
    public const PERMISSIONS = [self::VIEW_APPLICATIONS, self::MANAGE_COMMITTEES, self::VIEW_USERS];

    public const SOURCE_COMMAND = 'admin_grant_access_command';

    /**
     * The administration permissions the account holds now.
     *
     * @return list<string>
     */
    public function permissionsOf(User $user): array
    {
        return DB::table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->getKey())
            ->whereNull('user_roles.revoked_at')
            ->whereIn('permissions.code', self::PERMISSIONS)
            ->orderBy('permissions.code')
            ->distinct()
            ->pluck('permissions.code')
            ->all();
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsOf($user), true);
    }

    public function isAdmin(User $user): bool
    {
        return $this->permissionsOf($user) !== [];
    }

    /**
     * A current, unrevoked `award_admin` grant (the conflict-of-interest bar
     * on candidate submissions uses the role itself, not its permissions).
     */
    public function holdsAdminRole(int $userId): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->where('roles.code', self::ADMIN_ROLE)
            ->whereNull('user_roles.revoked_at')
            ->exists();
    }

    /**
     * A current `judge` grant, in one cycle or in any.
     */
    public function isJudge(int $userId, ?int $cycleId = null): bool
    {
        $now = now();

        return DB::table('cycle_user_roles')
            ->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')
            ->where('cycle_user_roles.user_id', $userId)
            ->where('roles.code', self::JUDGE_ROLE)
            ->whereNull('cycle_user_roles.revoked_at')
            ->where('cycle_user_roles.starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('cycle_user_roles.ends_at')->orWhere('cycle_user_roles.ends_at', '>', $now))
            ->when($cycleId !== null, fn ($query) => $query->where('cycle_user_roles.cycle_id', $cycleId))
            ->exists();
    }

    public function roleId(string $code): int
    {
        $id = DB::table('roles')->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("The {$code} role is missing: run \"php artisan migrate\" to provision it.");
        }

        return (int) $id;
    }

    /**
     * Grants `award_admin` to an existing, active account unless it already
     * holds it. Locks the account's users row first. Returns whether a grant
     * was (or, on a dry run, would be) recorded.
     */
    public function grantAdmin(User $user, bool $dryRun = false): bool
    {
        $roleId = $this->roleId(self::ADMIN_ROLE);

        return DB::transaction(function () use ($user, $roleId, $dryRun): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw new RuntimeException('This account is disabled; it is not given administration access.');
            }
            if ($this->holdsAdminRole((int) $locked->getKey())) {
                return false;
            }
            if ($dryRun) {
                return true;
            }

            $now = now();
            DB::table('user_roles')->insert([
                'user_id' => $locked->getKey(), 'role_id' => $roleId, 'assigned_by' => null, 'assigned_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            AuditLog::record('user_role.granted', null, 'user', (int) $locked->getKey(), ['role' => self::ADMIN_ROLE, 'source' => self::SOURCE_COMMAND]);

            return true;
        });
    }
}
