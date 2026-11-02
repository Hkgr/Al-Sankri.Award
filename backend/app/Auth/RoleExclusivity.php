<?php

namespace App\Auth;

use App\Models\User;
use App\Support\AuditLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Effective grants and the exclusive default candidate classification, across every cycle. */
class RoleExclusivity
{
    public const SOURCE = 'candidate_role_policy';

    public const UNAVAILABLE = 'candidate_role_unavailable';

    public const MESSAGE = 'حسابك مكلّف بدور آخر سارٍ، فلا يمكنه استخدام مسارات المترشح حالياً. يعود دور المترشح بعد انتهاء جميع التكليفات الأخرى.';

    public function platform(?CarbonInterface $at = null, bool $lock = false): Builder
    {
        $at = CarbonImmutable::instance($at ?? now())->utc();

        return DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereNull('user_roles.revoked_at')->where('user_roles.assigned_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('user_roles.starts_at')->orWhere('user_roles.starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('user_roles.ends_at')->orWhere('user_roles.ends_at', '>', $at))
            ->when($lock, fn (Builder $q) => $q->sharedLock());
    }

    public function cycles(?CarbonInterface $at = null, bool $lock = false): Builder
    {
        $at = CarbonImmutable::instance($at ?? now())->utc();

        return DB::table('cycle_user_roles')->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')
            ->whereNull('cycle_user_roles.revoked_at')->where('cycle_user_roles.starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('cycle_user_roles.ends_at')->orWhere('cycle_user_roles.ends_at', '>', $at))
            ->when($lock, fn (Builder $q) => $q->sharedLock());
    }

    public function hasOtherRoles(int $userId, ?CarbonInterface $at = null, bool $lock = false): bool
    {
        $at ??= now();

        return $this->platform($at, $lock)->where('user_roles.user_id', $userId)->where('roles.code', '<>', CandidateRole::CODE)->first(['user_roles.id']) !== null
            || $this->cycles($at, $lock)->where('cycle_user_roles.user_id', $userId)->first(['cycle_user_roles.id']) !== null;
    }

    public function isCandidate(User $user): bool
    {
        if (! $this->eligible($user)) {
            return false;
        }
        $at = now();
        $lock = DB::transactionLevel() > 0;

        return ! $this->hasOtherRoles((int) $user->id, $at, $lock)
            && $this->platform($at, $lock)->where('user_roles.user_id', $user->id)->where('roles.code', CandidateRole::CODE)->first(['user_roles.id']) !== null;
    }

    public function eligible(User $user): bool
    {
        return ! $user->trashed() && $user->disabled_reason !== 'account_removed'
            && ! Str::endsWith(Str::lower($user->email), '@deleted.invalid');
    }

    /** @return list<array<string, mixed>> */
    public function currentRoles(User $user): array
    {
        $at = now();
        $platform = $this->platform($at)->where('user_roles.user_id', $user->id)->orderBy('roles.code')->get(['roles.code', 'roles.name_ar'])
            ->map(fn (object $role): array => ['code' => $role->code, 'name' => $role->name_ar, 'scope' => 'platform', 'cycle' => null]);
        $cycles = $this->cycles($at)->join('award_cycles', 'award_cycles.id', '=', 'cycle_user_roles.cycle_id')->where('cycle_user_roles.user_id', $user->id)->orderBy('roles.code')
            ->get(['roles.code', 'roles.name_ar', 'award_cycles.id as cycle_id', 'award_cycles.name_ar as cycle_name', 'award_cycles.year'])
            ->map(fn (object $role): array => ['code' => $role->code, 'name' => $role->name_ar, 'scope' => 'cycle', 'cycle' => ['id' => (int) $role->cycle_id, 'name' => $role->cycle_name, 'year' => (int) $role->year]]);

        return $platform->merge($cycles)->all();
    }

    /** Never changes account status or candidature data. A dry run performs no writes or audit inserts. */
    public function reconcile(User|int $user, string $source = self::SOURCE, ?User $actor = null, bool $dryRun = false, ?int $cycleId = null): string
    {
        $id = $user instanceof User ? (int) $user->id : $user;

        return DB::transaction(function () use ($id, $source, $actor, $dryRun, $cycleId): string {
            $account = User::withTrashed()->whereKey($id)->lockForUpdate()->first();
            if ($account === null || ! $this->eligible($account)) {
                return 'skipped';
            }
            $at = now();
            $role = $this->role(CandidateRole::CODE, 'platform');
            $grants = DB::table('user_roles')->where('user_id', $id)->where('role_id', $role->id)->whereNull('revoked_at')->lockForUpdate()->get();
            $other = $this->hasOtherRoles($id, $at, true);
            $candidate = $this->platform($at, true)->where('user_roles.user_id', $id)->where('roles.code', CandidateRole::CODE)->first(['user_roles.id']) !== null;
            $action = $other ? ($grants->isEmpty() ? 'unchanged' : 'revoked') : ($candidate ? 'unchanged' : 'granted');
            if ($dryRun || $action === 'unchanged') {
                return $action;
            }
            if ($grants->isNotEmpty()) {
                DB::table('user_roles')->whereIn('id', $grants->pluck('id'))->update([
                    'revoked_at' => $at, 'revoked_by' => $actor?->id, 'revocation_source' => self::SOURCE, 'updated_at' => $at,
                ]);
                AuditLog::record('user_role.revoked', $actor?->id, 'user', $id, ['role' => CandidateRole::CODE, 'source' => $source, 'reason' => $other ? 'other_active_role' : 'inactive_candidate_grant'], $cycleId);
            }
            if (! $other) {
                DB::table('user_roles')->insert([
                    'user_id' => $id, 'role_id' => $role->id, 'assigned_by' => $actor?->id, 'assigned_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                ]);
                AuditLog::record('user_role.granted', $actor?->id, 'user', $id, ['role' => CandidateRole::CODE, 'source' => $source], $cycleId);
            }

            return $action;
        }, 3);
    }

    /** Accounts whose stored classification needs a change; used by preview, scheduled and request-time repair. */
    public function pendingUsers(): Builder
    {
        $at = now();
        $otherPlatform = $this->platform($at)->whereColumn('user_roles.user_id', 'users.id')->where('roles.code', '<>', CandidateRole::CODE)->selectRaw('1');
        $otherCycle = $this->cycles($at)->whereColumn('cycle_user_roles.user_id', 'users.id')->selectRaw('1');
        $candidate = $this->platform($at)->whereColumn('user_roles.user_id', 'users.id')->where('roles.code', CandidateRole::CODE)->selectRaw('1');
        $unrevokedCandidate = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereColumn('user_roles.user_id', 'users.id')->where('roles.code', CandidateRole::CODE)->whereNull('user_roles.revoked_at')->selectRaw('1');

        return DB::table('users')->whereNull('users.deleted_at')->where('users.email', 'not like', '%@deleted.invalid')
            ->where(fn (Builder $q) => $q->whereNull('users.disabled_reason')->orWhere('users.disabled_reason', '<>', 'account_removed'))
            ->where(function (Builder $q) use ($otherPlatform, $otherCycle, $candidate, $unrevokedCandidate): void {
                $q->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereExists(clone $otherPlatform)->orWhereExists(clone $otherCycle))->whereExists($unrevokedCandidate))
                    ->orWhere(fn (Builder $q) => $q->whereNotExists($otherPlatform)->whereNotExists($otherCycle)->whereNotExists($candidate));
            });
    }

    /** @return array{granted: int, revoked: int, unchanged: int, skipped: int} */
    public function reconcilePending(bool $apply = false, ?int $userId = null): array
    {
        $counts = ['granted' => 0, 'revoked' => 0, 'unchanged' => 0, 'skipped' => 0];
        $query = $userId === null ? $this->pendingUsers() : DB::table('users')->where('users.id', $userId);
        $query->select('users.id')->orderBy('users.id')->chunkById(200, function ($rows) use (&$counts, $apply): void {
            foreach ($rows as $row) {
                $counts[$this->reconcile((int) $row->id, self::SOURCE, dryRun: ! $apply)]++;
            }
        }, 'users.id', 'id');

        return $counts;
    }

    public function grantPlatform(User $user, string $code, string $source, ?User $actor = null, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null, bool $dryRun = false): bool
    {
        $startsAt = $startsAt === null ? null : CarbonImmutable::instance($startsAt)->utc();
        $endsAt = $endsAt === null ? null : CarbonImmutable::instance($endsAt)->utc();
        if ($code === CandidateRole::CODE) {
            return $this->reconcile($user, $source, $actor, $dryRun) === 'granted';
        }

        return DB::transaction(function () use ($user, $code, $source, $actor, $startsAt, $endsAt, $dryRun): bool {
            $account = $this->lockAccounts($user, $actor);
            $role = $this->role($code, 'platform');
            $existing = $this->platform(lock: true)->where('user_roles.user_id', $account->id)->where('roles.code', $code)->first(['user_roles.id']);
            if ($existing !== null) {
                return in_array($this->reconcile($account, $source, $actor, $dryRun), ['granted', 'revoked'], true);
            }
            $this->checkWindow($startsAt ?? now(), $endsAt);
            $old = DB::table('user_roles')->where('user_id', $account->id)->where('role_id', $role->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($old !== null && ($old->ends_at === null || $old->ends_at > now()->format('Y-m-d H:i:s'))) {
                return in_array($this->reconcile($account, $source, $actor, $dryRun), ['granted', 'revoked'], true);
            }
            if ($dryRun) {
                return true;
            }
            if ($old !== null) {
                DB::table('user_roles')->where('id', $old->id)->update(['revoked_at' => now(), 'revoked_by' => $actor?->id, 'revocation_source' => self::SOURCE, 'updated_at' => now()]);
                AuditLog::record('user_role.revoked', $actor?->id, 'user', (int) $account->id, ['role' => $code, 'source' => $source]);
            }
            DB::table('user_roles')->insert(['user_id' => $account->id, 'role_id' => $role->id, 'assigned_by' => $actor?->id, 'assigned_at' => now(), 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::record('user_role.granted', $actor?->id, 'user', (int) $account->id, ['role' => $code, 'source' => $source]);
            $this->reconcile($account, $source, $actor);

            return true;
        }, 3);
    }

    public function revokePlatform(User $user, string $code, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $code, $actor): void {
            $account = $this->lockAccounts($user, $actor, allowInactiveTarget: true);
            $role = $this->role($code, 'platform');
            $changed = DB::table('user_roles')->where('user_id', $account->id)->where('role_id', $role->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $actor?->id, 'revocation_source' => self::SOURCE, 'updated_at' => now()]);
            if ($changed > 0) {
                AuditLog::record('user_role.revoked', $actor?->id, 'user', (int) $account->id, ['role' => $code, 'source' => self::SOURCE]);
            }
            $this->reconcile($account, self::SOURCE, $actor);
        }, 3);
    }

    public function grantCycle(User $user, string $code, int $cycleId, User $actor, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): void
    {
        $startsAt = $startsAt === null ? null : CarbonImmutable::instance($startsAt)->utc();
        $endsAt = $endsAt === null ? null : CarbonImmutable::instance($endsAt)->utc();
        DB::transaction(function () use ($user, $code, $cycleId, $actor, $startsAt, $endsAt): void {
            $account = $this->lockAccounts($user, $actor);
            $role = $this->role($code, 'cycle');
            $this->checkWindow($startsAt ?? now(), $endsAt);
            $existing = DB::table('cycle_user_roles')->where('user_id', $account->id)->where('cycle_id', $cycleId)->where('role_id', $role->id)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($existing !== null && $existing->ends_at !== null && $existing->ends_at <= now()->format('Y-m-d H:i:s')) {
                DB::table('cycle_user_roles')->where('id', $existing->id)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => 'role_window_ended', 'updated_at' => now()]);
                AuditLog::record('cycle_user_role.revoked', (int) $actor->id, 'user', (int) $account->id, ['role' => $code, 'cycle_id' => $cycleId], $cycleId);
                $existing = null;
            }
            if ($existing === null) {
                DB::table('cycle_user_roles')->insert(['cycle_id' => $cycleId, 'user_id' => $account->id, 'role_id' => $role->id, 'assigned_by' => $actor->id, 'starts_at' => $startsAt ?? now(), 'ends_at' => $endsAt, 'created_at' => now(), 'updated_at' => now()]);
                AuditLog::record('cycle_user_role.granted', (int) $actor->id, 'user', (int) $account->id, ['role' => $code, 'cycle_id' => $cycleId], $cycleId);
            }
            $this->reconcile($account, self::SOURCE, $actor, cycleId: $cycleId);
        }, 3);
    }

    public function revokeCycle(User $user, string $code, int $cycleId, User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $code, $cycleId, $actor, $reason): void {
            $account = $this->lockAccounts($user, $actor, allowInactiveTarget: true);
            $role = $this->role($code, 'cycle');
            $changed = DB::table('cycle_user_roles')->where('user_id', $account->id)->where('cycle_id', $cycleId)->where('role_id', $role->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason, 'updated_at' => now()]);
            if ($changed > 0) {
                AuditLog::record('cycle_user_role.revoked', (int) $actor->id, 'user', (int) $account->id, ['role' => $code, 'cycle_id' => $cycleId], $cycleId);
            }
            $this->reconcile($account, self::SOURCE, $actor, cycleId: $cycleId);
        }, 3);
    }

    private function role(string $code, string $scope): object
    {
        return DB::table('roles')->where('code', $code)->where('scope', $scope)->first()
            ?? throw new RuntimeException('The role is missing or has the wrong scope. Run migrations before assigning roles.');
    }

    private function lockAccounts(User $user, ?User $actor, bool $allowInactiveTarget = false): User
    {
        $target = null;
        foreach (collect([$user->id, $actor?->id])->filter()->unique()->sort()->values() as $id) {
            $query = User::query()->whereKey($id);
            $id === $user->id ? $query->lockForUpdate() : $query->sharedLock();
            $account = $query->firstOrFail();
            if ((! $account->isActive() && ! ($allowInactiveTarget && $id === $user->id)) || ! $this->eligible($account)) {
                throw new RuntimeException('This account cannot receive role assignments.');
            }
            if ($id === $user->id) {
                $target = $account;
            }
        }

        return $target ?? throw new RuntimeException('The account was not found.');
    }

    private function checkWindow(CarbonInterface $startsAt, ?CarbonInterface $endsAt): void
    {
        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new RuntimeException('A role assignment must end after it starts.');
        }
    }
}
