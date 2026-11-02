<?php

namespace App\Admin;

use App\Auth\RoleExclusivity;
use App\Models\User;
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

    public const REMOVE_ACCOUNTS = 'users.remove_accounts';

    /** Exports (with the source module's own viewing permission). */
    public const EXPORT_REPORTS = 'reports.export';

    public const VIEW_AUDIT = 'audit.view';

    public const VIEW_SYSTEM_LOGS = 'logs.view_system';

    /** Referral or exclusion of a pending submission (with VIEW_APPLICATIONS). */
    public const REVIEW_APPLICATIONS = 'applications.review';

    /** Registration and judging windows («المواعيد»). */
    public const MANAGE_SCHEDULE = 'schedules.manage';

    /** The administration permissions this dashboard knows (anything else is ignored). */
    public const PERMISSIONS = [self::VIEW_APPLICATIONS, self::MANAGE_COMMITTEES, self::VIEW_USERS, self::EXPORT_REPORTS, self::VIEW_AUDIT, self::VIEW_SYSTEM_LOGS, self::REVIEW_APPLICATIONS, self::MANAGE_SCHEDULE, self::REMOVE_ACCOUNTS];

    public const SOURCE_COMMAND = 'admin_grant_access_command';

    /**
     * The administration permissions the account holds now.
     *
     * @return list<string>
     */
    public function permissionsOf(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }

        return app(RoleExclusivity::class)->platform(lock: DB::transactionLevel() > 0)
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->getKey())
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
        return app(RoleExclusivity::class)->platform(lock: DB::transactionLevel() > 0)
            ->where('user_roles.user_id', $userId)->where('roles.code', self::ADMIN_ROLE)->first(['user_roles.id']) !== null;
    }

    /**
     * A current `judge` grant, in one cycle or in any.
     */
    public function isJudge(int $userId, ?int $cycleId = null): bool
    {
        return app(RoleExclusivity::class)->cycles(lock: DB::transactionLevel() > 0)
            ->where('cycle_user_roles.user_id', $userId)->where('roles.code', self::JUDGE_ROLE)
            ->when($cycleId !== null, fn ($query) => $query->where('cycle_user_roles.cycle_id', $cycleId))
            ->first(['cycle_user_roles.id']) !== null;
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
        return app(RoleExclusivity::class)->grantPlatform($user, self::ADMIN_ROLE, self::SOURCE_COMMAND, dryRun: $dryRun);
    }
}
