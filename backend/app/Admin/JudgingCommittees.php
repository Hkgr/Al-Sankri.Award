<?php

namespace App\Admin;

use App\Auth\GoogleAccounts;
use App\Candidates\NominationTaxonomy;
use App\Judging\Assignments;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * «لجان الحكام»: one judging committee per MAIN domain of a cycle
 * (committees.type = judging, cycle_domain_id = that domain), its members
 * (committee_members) and their `judge` grant (cycle_user_roles). There is
 * no committee, field or classification per subcategory.
 *
 * Rules, all checked again inside each write's transaction:
 * - a committee is created only when asked, as a draft (one current per
 *   cycle and domain: uq_committees_current);
 * - members are added and ended only while the committee is a draft; an
 *   approved committee goes back to draft explicitly first, which is
 *   refused once judging assignments exist for it;
 * - approval needs exactly 3 or 5 current members, all with active accounts;
 * - a person has at most one current judging membership per cycle (also
 *   guarded by uq_cycle_user_roles_current on the judge grant);
 * - a disabled account, or one that submitted an application in this
 *   cycle, is not appointed (and an appointed judge cannot submit there:
 *   App\Candidates\Submissions);
 * - ending a membership keeps its row (left_at) and revokes the grant;
 *   it is refused once judging assignments exist for that member.
 *
 * Lock order: account rows first (the administrator's shared, the judge's
 * for update, in ascending id order; a new account's row is the newest),
 * then the committee row; the submission locks the candidate's row first
 * too, so an appointment and a submission by the same person serialize.
 * Every change is recorded in audit_logs, attributed to the administrator.
 */
class JudgingCommittees
{
    public const CREATED = 'created';

    public const APPOINTED = 'appointed';

    public const UPDATED = 'updated';

    public const COMMITTEE_EXISTS = 'committee_exists';

    public const COMMITTEE_MISSING = 'committee_missing';

    public const COMMITTEE_APPROVED = 'committee_approved';

    public const COMMITTEE_NOT_APPROVED = 'committee_not_approved';

    public const DOMAIN_UNKNOWN = 'domain_unknown';

    public const ACCOUNT_EXISTS = 'account_exists';

    public const ACCOUNT_MISSING = 'account_missing';

    /** The confirmed account is no longer the one this email names (or no binding was sent). */
    public const ACCOUNT_CHANGED = 'account_changed';

    public const ACCOUNT_DISABLED = 'account_disabled';

    public const ALREADY_JUDGE = 'already_judge';

    public const SUBMITTED_CANDIDATE = 'submitted_candidate';

    public const MEMBER_NOT_FOUND = 'member_not_found';

    public const JUDGING_HISTORY = 'judging_history';

    public const WRONG_SIZE = 'wrong_size';

    public const INACTIVE_MEMBERS = 'inactive_members';

    /** Approval needs exactly one of these numbers of current judges. */
    public const ALLOWED_SIZES = [3, 5];

    public const LINK_SENT = 'sent';

    public const LINK_THROTTLED = 'throttled';

    public const LINK_FAILED = 'failed';

    public function __construct(private readonly AdminAccess $access, private readonly GoogleAccounts $google) {}

    /**
     * The cycle's main domains in the approved order, each with its judging
     * committee (or null) and members.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(int $cycleId): array
    {
        $domains = $this->domains($cycleId);
        $committees = DB::table('committees')->where('cycle_id', $cycleId)->where('type', 'judging')->where('status', '<>', 'dissolved')
            ->get()->keyBy('cycle_domain_id');

        return array_map(function (object $domain) use ($committees): array {
            $committee = $committees->get($domain->cycle_domain_id);

            return [
                'domain' => ['code' => $domain->code, 'name' => $domain->name_ar],
                'committee' => $committee === null ? null : $this->committeeState($committee, $domain),
            ];
        }, $domains);
    }

    /**
     * Creates the draft judging committee of one main domain.
     *
     * @return array{0: string, 1: int|null}
     */
    public function create(User $actor, int $cycleId, string $domainCode, string $name): array
    {
        $domain = $this->domain($cycleId, $domainCode);
        if ($domain === null) {
            return [self::DOMAIN_UNKNOWN, null];
        }

        try {
            return DB::transaction(function () use ($actor, $cycleId, $domain, $name): array {
                $this->lockAccounts($actor, null);
                if ($this->committee($cycleId, (int) $domain->cycle_domain_id, lock: true) !== null) {
                    return [self::COMMITTEE_EXISTS, null];
                }

                $now = now();
                $id = DB::table('committees')->insertGetId([
                    'cycle_id' => $cycleId, 'cycle_domain_id' => $domain->cycle_domain_id, 'type' => 'judging', 'name_ar' => $name,
                    'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
                ]);
                AuditLog::record('committee.created', (int) $actor->getKey(), 'committee', $id, ['cycle_id' => $cycleId, 'domain' => $domain->code, 'type' => 'judging'], $cycleId);

                return [self::CREATED, $id];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created it first.
            return [self::COMMITTEE_EXISTS, null];
        }
    }

    /**
     * What the dialog shows about an existing account, before the
     * administrator decides to appoint it. Null when no account uses the
     * email.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(int $cycleId, string $email): ?array
    {
        $user = User::query()->where('email', $email)->first();

        return $user === null ? null : $this->accountSummary($user, $cycleId);
    }

    /**
     * Appoints a judge to the committee of one main domain: an existing
     * account (when $existing, explicitly confirmed) or a new account
     * created here. A new account has no password and an unverified email;
     * its owner sets a password through the password-reset link sent after
     * the commit, independently of the appointment.
     *
     * An existing account is appointed only with the `account_ref` of the
     * lookup the administrator confirmed (accountRef()): it binds the exact
     * account and its normalized email, and it is checked again under that
     * account's lock. Another account, or the same account with another
     * email, is refused (account_changed); it is never resolved by email
     * alone.
     *
     * @param  array{name: string, email: string, phone: string|null, specialty: string|null}  $input
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function appoint(User $actor, int $cycleId, string $domainCode, array $input, bool $existing, ?string $accountRef = null): array
    {
        $domain = $this->domain($cycleId, $domainCode);
        if ($domain === null) {
            return [self::DOMAIN_UNKNOWN, null];
        }

        $target = User::query()->where('email', $input['email'])->first();
        if ($target === null && $existing) {
            return [self::ACCOUNT_MISSING, null];
        }
        if ($target !== null && ! $existing) {
            return [self::ACCOUNT_EXISTS, $this->accountSummary($target, $cycleId)];
        }
        if ($existing && ! is_string($accountRef)) {
            return [self::ACCOUNT_CHANGED, null];
        }

        try {
            $result = DB::transaction(function () use ($actor, $cycleId, $domain, $input, $target, $accountRef): array {
                $created = $target === null;
                $target = $created ? null : $this->lockAccounts($actor, $target);
                // As now committed, under the account's lock: the confirmed account and email, or nothing.
                if (! $created && ($target === null || $target->email !== $input['email'] || ! hash_equals($this->accountRef($target), (string) $accountRef))) {
                    throw new RefusedChange(self::ACCOUNT_CHANGED);
                }
                if ($created) {
                    $this->lockAccounts($actor, null);
                    // The newest row: the account locks stay in ascending id order.
                    $target = new User;
                    $target->forceFill([
                        'name' => $input['name'],
                        'email' => $input['email'],
                        'phone' => $input['phone'],
                        'password' => null,
                        'status' => User::STATUS_ACTIVE,
                    ])->save();
                }

                $committee = $this->committee($cycleId, (int) $domain->cycle_domain_id, lock: true);
                if ($committee === null) {
                    throw new RefusedChange(self::COMMITTEE_MISSING);
                }
                if ($committee->status !== 'draft') {
                    throw new RefusedChange(self::COMMITTEE_APPROVED);
                }
                if (! $target->isActive()) {
                    throw new RefusedChange(self::ACCOUNT_DISABLED);
                }
                if ($this->judgingMembership((int) $target->getKey(), $cycleId) !== null || $this->access->isJudge((int) $target->getKey(), $cycleId)) {
                    throw new RefusedChange(self::ALREADY_JUDGE, $this->accountSummary($target, $cycleId));
                }
                if ($this->submittedIn((int) $target->getKey(), $cycleId)) {
                    throw new RefusedChange(self::SUBMITTED_CANDIDATE);
                }

                $now = now();
                $memberId = DB::table('committee_members')->insertGetId([
                    'committee_id' => $committee->id, 'user_id' => $target->getKey(), 'position' => 'member',
                    'specialty' => $input['specialty'], 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('cycle_user_roles')->insert([
                    'cycle_id' => $cycleId, 'user_id' => $target->getKey(), 'role_id' => $this->access->roleId(AdminAccess::JUDGE_ROLE),
                    'assigned_by' => $actor->getKey(), 'starts_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $actorId = (int) $actor->getKey();
                if ($created) {
                    AuditLog::record('user.created', $actorId, 'user', (int) $target->getKey(), ['source' => 'judge_appointment'], $cycleId);
                }
                AuditLog::record('cycle_user_role.granted', $actorId, 'user', (int) $target->getKey(), ['role' => AdminAccess::JUDGE_ROLE, 'cycle_id' => $cycleId], $cycleId);
                AuditLog::record('committee_member.added', $actorId, 'committee', (int) $committee->id, [
                    'committee_member_id' => $memberId, 'user_id' => $target->getKey(), 'domain' => $domain->code,
                ], $cycleId);

                return ['member_id' => $memberId, 'user' => $target, 'created' => $created];
            });
        } catch (RefusedChange $refusal) {
            // Rolled back: nothing of this request, a new account included, was kept.
            return [$refusal->outcome, $refusal->data];
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created this email, or appointed this
            // person, first; nothing of this request was kept.
            $now = User::query()->where('email', $input['email'])->first();

            return $target === null && $now !== null ? [self::ACCOUNT_EXISTS, $this->accountSummary($now, $cycleId)] : [self::ALREADY_JUDGE, null];
        }

        return [self::APPOINTED, [
            'member_id' => $result['member_id'],
            'account_created' => $result['created'],
            // Sent after the commit: a mail failure never undoes the appointment.
            'setup_link' => $result['created'] ? $this->sendSetupLink($result['user']) : null,
        ]];
    }

    /**
     * Ends a current membership of a draft committee (the row stays, with
     * left_at) and revokes the member's judge grant for the cycle.
     */
    public function end(User $actor, int $cycleId, string $domainCode, int $memberId): string
    {
        $domain = $this->domain($cycleId, $domainCode);
        $member = DB::table('committee_members')->where('id', $memberId)->first();
        if ($domain === null || $member === null) {
            return self::MEMBER_NOT_FOUND;
        }

        return DB::transaction(function () use ($actor, $cycleId, $domain, $member): string {
            $target = $this->lockAccounts($actor, User::query()->find($member->user_id));
            $committee = $this->committee($cycleId, (int) $domain->cycle_domain_id, lock: true);
            $member = DB::table('committee_members')->where('id', $member->id)->lockForUpdate()->first();

            if ($committee === null || $target === null || (int) $member->committee_id !== (int) $committee->id || $member->left_at !== null) {
                return self::MEMBER_NOT_FOUND;
            }
            if ($committee->status !== 'draft') {
                return self::COMMITTEE_APPROVED;
            }
            if (DB::table('judging_assignments')->where('committee_member_id', $member->id)->exists()) {
                return self::JUDGING_HISTORY;
            }

            $now = now();
            DB::table('committee_members')->where('id', $member->id)->update(['left_at' => $now, 'updated_at' => $now]);
            DB::table('cycle_user_roles')
                ->where('cycle_id', $cycleId)->where('user_id', $target->getKey())->where('role_id', $this->access->roleId(AdminAccess::JUDGE_ROLE))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'revoked_by' => $actor->getKey(), 'revocation_reason' => 'انتهاء العضوية في لجنة التحكيم', 'updated_at' => $now]);

            $actorId = (int) $actor->getKey();
            AuditLog::record('committee_member.ended', $actorId, 'committee', (int) $committee->id, ['committee_member_id' => $member->id, 'user_id' => $target->getKey()], $cycleId);
            AuditLog::record('cycle_user_role.revoked', $actorId, 'user', (int) $target->getKey(), ['role' => AdminAccess::JUDGE_ROLE, 'cycle_id' => $cycleId], $cycleId);

            return self::UPDATED;
        });
    }

    /**
     * Approves a draft committee of exactly 3 or 5 current judges whose
     * accounts are all active.
     */
    public function approve(User $actor, int $cycleId, string $domainCode): string
    {
        $domain = $this->domain($cycleId, $domainCode);
        if ($domain === null) {
            return self::DOMAIN_UNKNOWN;
        }

        return DB::transaction(function () use ($actor, $cycleId, $domain): string {
            $this->lockAccounts($actor, null);
            $committee = $this->committee($cycleId, (int) $domain->cycle_domain_id, lock: true);
            if ($committee === null) {
                return self::COMMITTEE_MISSING;
            }
            if ($committee->status !== 'draft') {
                return self::COMMITTEE_APPROVED;
            }

            $members = DB::table('committee_members')->join('users', 'users.id', '=', 'committee_members.user_id')
                ->where('committee_members.committee_id', $committee->id)->whereNull('committee_members.left_at')
                ->get(['committee_members.id', 'users.status']);
            if (! in_array($members->count(), self::ALLOWED_SIZES, true)) {
                return self::WRONG_SIZE;
            }
            if ($members->contains(fn (object $member): bool => $member->status !== User::STATUS_ACTIVE)) {
                return self::INACTIVE_MEMBERS;
            }

            $now = now();
            DB::table('committees')->where('id', $committee->id)->update(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => $now, 'updated_at' => $now]);
            AuditLog::record('committee.approved', (int) $actor->getKey(), 'committee', (int) $committee->id, ['member_count' => $members->count(), 'domain' => $domain->code], $cycleId);
            app(Assignments::class)->provisionCommittee((int) $committee->id, (int) $actor->getKey());

            return self::UPDATED;
        });
    }

    /**
     * Returns an approved committee to draft, so its membership can change.
     * Refused once judging assignments exist for it.
     */
    public function returnToDraft(User $actor, int $cycleId, string $domainCode): string
    {
        $domain = $this->domain($cycleId, $domainCode);
        if ($domain === null) {
            return self::DOMAIN_UNKNOWN;
        }

        return DB::transaction(function () use ($actor, $cycleId, $domain): string {
            $this->lockAccounts($actor, null);
            $committee = $this->committee($cycleId, (int) $domain->cycle_domain_id, lock: true);
            if ($committee === null) {
                return self::COMMITTEE_MISSING;
            }
            if ($committee->status !== 'approved') {
                return self::COMMITTEE_NOT_APPROVED;
            }
            if (DB::table('judging_assignments')->where('committee_id', $committee->id)->exists()) {
                return self::JUDGING_HISTORY;
            }

            DB::table('committees')->where('id', $committee->id)->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
            AuditLog::record('committee.returned_to_draft', (int) $actor->getKey(), 'committee', (int) $committee->id, ['domain' => $domain->code], $cycleId);

            return self::UPDATED;
        });
    }

    /**
     * Sends a member's account the password-reset link again (the first
     * access of an account created here). Only for a member of this
     * committee whose account has no password and no Google sign-in yet.
     */
    public function resendSetupLink(int $cycleId, string $domainCode, int $memberId): ?string
    {
        $domain = $this->domain($cycleId, $domainCode);
        $committee = $domain === null ? null : $this->committee($cycleId, (int) $domain->cycle_domain_id);
        $member = $committee === null ? null : DB::table('committee_members')->where('id', $memberId)->where('committee_id', $committee->id)->first();
        $user = $member === null ? null : User::query()->find($member->user_id);

        if ($user === null || ! $user->isActive() || ! $this->setupPending($user)) {
            return null;
        }

        return $this->sendSetupLink($user);
    }

    /**
     * The password-reset link through the existing broker and mailer: its
     * own expiry and throttle apply. Never throws.
     */
    private function sendSetupLink(User $user): string
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $exception) {
            report($exception);

            return self::LINK_FAILED;
        }

        return match ($status) {
            Password::RESET_LINK_SENT => self::LINK_SENT,
            Password::RESET_THROTTLED => self::LINK_THROTTLED,
            default => self::LINK_FAILED,
        };
    }

    /**
     * Locks the administrator's users row (shared: audit rows reference it)
     * and the judge's (for update), in ascending id order, and returns the
     * judge's row as now committed.
     */
    private function lockAccounts(User $actor, ?User $target): ?User
    {
        $actorId = (int) $actor->getKey();
        $targetId = $target === null ? null : (int) $target->getKey();

        if ($targetId === null || $targetId > $actorId) {
            DB::table('users')->where('id', $actorId)->sharedLock()->first();
        }
        $locked = $targetId === null ? null : User::query()->whereKey($targetId)->lockForUpdate()->first();
        if ($targetId !== null && $targetId < $actorId) {
            DB::table('users')->where('id', $actorId)->sharedLock()->first();
        }

        return $locked;
    }

    private function committee(int $cycleId, int $cycleDomainId, bool $lock = false): ?object
    {
        return DB::table('committees')->where('cycle_id', $cycleId)->where('cycle_domain_id', $cycleDomainId)
            ->where('type', 'judging')->where('status', '<>', 'dissolved')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    /**
     * The cycle's main domains of the approved taxonomy, in its order.
     *
     * @return list<object>
     */
    private function domains(int $cycleId): array
    {
        $rows = DB::table('cycle_domains')->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->where('cycle_domains.cycle_id', $cycleId)->whereIn('award_domains.code', NominationTaxonomy::domainCodes())
            ->get(['cycle_domains.id as cycle_domain_id', 'award_domains.code', 'cycle_domains.name_ar'])->keyBy('code');

        return array_values(array_filter(array_map(fn (string $code): ?object => $rows->get($code), NominationTaxonomy::domainCodes())));
    }

    private function domain(int $cycleId, string $domainCode): ?object
    {
        foreach ($this->domains($cycleId) as $domain) {
            if ($domain->code === $domainCode) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * The person's current judging membership in this cycle, with its domain.
     */
    private function judgingMembership(int $userId, int $cycleId): ?object
    {
        return DB::table('committee_members')
            ->join('committees', 'committees.id', '=', 'committee_members.committee_id')
            ->join('cycle_domains', 'cycle_domains.id', '=', 'committees.cycle_domain_id')
            ->where('committee_members.user_id', $userId)->whereNull('committee_members.left_at')
            ->where('committees.cycle_id', $cycleId)->where('committees.type', 'judging')->where('committees.status', '<>', 'dissolved')
            ->first(['cycle_domains.name_ar as domain_name']);
    }

    private function submittedIn(int $userId, int $cycleId): bool
    {
        return DB::table('applications')->where('cycle_id', $cycleId)->where('created_by', $userId)->whereNotNull('current_version_id')->exists();
    }

    /**
     * Binds a confirmation to one account and its normalized email: an HMAC
     * with the application key, so it reveals neither and cannot be made
     * for another account.
     */
    public function accountRef(User $user): string
    {
        return hash_hmac('sha256', 'judge-appointment|'.$user->getKey().'|'.mb_strtolower((string) $user->email), (string) config('app.key'));
    }

    private function setupPending(User $user): bool
    {
        return ! $user->hasPassword() && ! $this->google->isLinked($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountSummary(User $user, int $cycleId): array
    {
        return [
            'account_ref' => $this->accountRef($user),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'active' => $user->isActive(),
            'email_verified' => $user->hasVerifiedEmail(),
            'setup_pending' => $this->setupPending($user),
            'is_admin' => $this->access->holdsAdminRole((int) $user->getKey()),
            'judging_domain' => $this->judgingMembership((int) $user->getKey(), $cycleId)?->domain_name,
            'submitted_in_cycle' => $this->submittedIn((int) $user->getKey(), $cycleId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function committeeState(object $committee, object $domain): array
    {
        $members = DB::table('committee_members')->join('users', 'users.id', '=', 'committee_members.user_id')
            ->where('committee_members.committee_id', $committee->id)
            ->orderBy('committee_members.joined_at')->orderBy('committee_members.id')
            ->get(['committee_members.id', 'committee_members.user_id', 'committee_members.specialty', 'committee_members.joined_at', 'committee_members.left_at',
                'users.name', 'users.email', 'users.phone', 'users.status', 'users.password', 'users.email_verified_at']);
        $linked = DB::table('oauth_identities')->whereIn('user_id', $members->pluck('user_id'))->pluck('user_id')->flip();

        $rows = $members->map(fn (object $member): array => [
            'id' => (int) $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'specialty' => $member->specialty,
            'domain' => $domain->name_ar,
            'joined_at' => $this->iso($member->joined_at),
            'left_at' => $this->iso($member->left_at),
            'account' => [
                'active' => $member->status === User::STATUS_ACTIVE,
                'email_verified' => $member->email_verified_at !== null,
                'setup_pending' => $member->password === null && ! $linked->has($member->user_id),
            ],
        ]);
        $current = $rows->whereNull('left_at')->values();

        return [
            'id' => (int) $committee->id,
            'name' => $committee->name_ar,
            'status' => $committee->status,
            'approved_at' => $this->iso($committee->approved_at),
            'current_count' => $current->count(),
            'allowed_sizes' => self::ALLOWED_SIZES,
            'has_judging_history' => DB::table('judging_assignments')->where('committee_id', $committee->id)->exists(),
            'members' => $current->all(),
            'former_members' => $rows->whereNotNull('left_at')->values()->all(),
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value, 'UTC')->toIso8601ZuluString();
    }
}
