<?php

namespace App\Admin;

use App\Auth\CandidateRole;
use App\Candidates\NominationCycles;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «المستخدمون»: every registered account, once, with its current roles and
 * its candidate progress in ONE selected cycle, read from stored records
 * only (nothing here changes an account).
 *
 * The query starts from `users`; the candidate records are left joins that
 * are one-to-one by their unique keys (uq_candidate_profiles_user,
 * uq_candidate_nominations_journey, uq_candidate_nominations_application),
 * so an account appears exactly once whatever it has or lacks.
 *
 * Progress, for the selected cycle (one SQL expression, used both for the
 * label and for the filter):
 * - Stage 1: a candidate_profiles row (it exists only once confirmed);
 * - Stage 2: the candidate_nominations row of that profile and THIS cycle;
 * - Stage 3: confirmed only while documentation_confirmed_revision equals
 *   documentation_revision (CandidateNomination::documentationConfirmed);
 *   a later saved change makes it incomplete again;
 * - submitted: the journey's application has a submitted version
 *   (current_version_id), whatever its workflow status became since.
 * Unsaved form input is never stored, so there is no "started" state. A
 * closed cycle keeps what was completed; whether the stage is open now is
 * reported separately. Another cycle's participation never counts.
 */
class RegisteredUsers
{
    /** Overall states, in journey order, with not_required for staff accounts without a candidature. */
    public const PROGRESS = [
        'no_basic_information',
        'awaiting_nomination',
        'awaiting_documentation',
        'documentation_incomplete',
        'ready',
        'submitted',
        'not_required',
        // No cycle exists: only Stage 1 can be known.
        'basic_information_only',
    ];

    public const ROLE_FILTERS = ['candidate', 'award_admin', 'judge'];

    public const SORTS = ['name' => 'users.name', 'registered_at' => 'users.created_at', 'last_login_at' => 'users.last_login_at'];

    public const PER_PAGE = [10, 25, 50];

    public function __construct(private readonly NominationCycles $cycles) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(array $filters, ?int $cycleId, bool $canViewApplications): array
    {
        [$progressSql, $progressBindings] = $this->progressExpression($cycleId);
        $query = $this->base($cycleId);
        $this->filter($query, $filters, $cycleId, $progressSql, $progressBindings);

        $total = (clone $query)->count();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $sort = self::SORTS[$filters['sort'] ?? 'registered_at'] ?? self::SORTS['registered_at'];
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $query
            ->select([
                'users.id', 'users.name', 'users.email', 'users.email_verified_at', 'users.status', 'users.created_at', 'users.last_login_at',
                'profiles.id as profile_id', 'journeys.id as journey_id', 'journeys.documentation_revision',
                'applications.id as application_id', 'applications.reference_number', 'applications.first_submitted_at', 'applications.status as application_status',
            ])
            ->selectRaw("({$progressSql}) as progress", $progressBindings)
            // Accounts that never signed in come last in either direction.
            ->when($sort === self::SORTS['last_login_at'], fn (Builder $q) => $q->orderByRaw('CASE WHEN users.last_login_at IS NULL THEN 1 ELSE 0 END'))
            ->orderBy($sort, $direction)
            ->orderBy('users.id', $direction)
            ->forPage($page, $perPage)
            ->get();

        $roles = $this->rolesOf($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        return [
            'data' => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'email_verified' => $row->email_verified_at !== null,
                'active' => $row->status === 'active',
                'registered_at' => $this->iso($row->created_at),
                'last_login_at' => $this->iso($row->last_login_at),
                'roles' => $roles[(int) $row->id] ?? ['platform' => [], 'cycle' => []],
                'progress' => $this->stages($row),
                'submission' => $row->progress !== 'submitted' ? null : [
                    // The read-only application page, for administrators allowed to open it.
                    'application_id' => $canViewApplications ? (int) $row->application_id : null,
                    'reference_number' => $row->reference_number,
                    'submitted_at' => $this->iso($row->first_submitted_at),
                    'status' => $row->application_status,
                ],
            ])->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage, 'cycle' => $this->cycleMeta($cycleId)],
        ];
    }

    private function base(?int $cycleId): Builder
    {
        return DB::table('users')
            ->leftJoin('candidate_profiles as profiles', 'profiles.user_id', '=', 'users.id')
            // The journey of THIS cycle only (0 matches nothing when no cycle exists).
            ->leftJoin('candidate_nominations as journeys', function (JoinClause $join) use ($cycleId): void {
                $join->on('journeys.candidate_profile_id', '=', 'profiles.id')->where('journeys.cycle_id', '=', $cycleId ?? 0);
            })
            ->leftJoin('applications', 'applications.id', '=', 'journeys.application_id');
    }

    /**
     * The overall state as one SQL expression (label and filter alike).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function progressExpression(?int $cycleId): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $profileOnly = $cycleId === null ? 'basic_information_only' : 'awaiting_nomination';
        $grant = fn (string $role): string => "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = users.id AND ur.revoked_at IS NULL AND r.code = '{$role}')";
        $judge = 'EXISTS (SELECT 1 FROM cycle_user_roles cr JOIN roles r ON r.id = cr.role_id WHERE cr.user_id = users.id AND cr.revoked_at IS NULL'
            ." AND r.code = '".AdminAccess::JUDGE_ROLE."' AND cr.starts_at <= ? AND (cr.ends_at IS NULL OR cr.ends_at > ?))";

        $sql = 'CASE'
            ." WHEN applications.current_version_id IS NOT NULL THEN 'submitted'"
            ." WHEN journeys.id IS NOT NULL AND journeys.documentation_confirmed_revision = journeys.documentation_revision THEN 'ready'"
            ." WHEN journeys.id IS NOT NULL AND journeys.documentation_revision > 0 THEN 'documentation_incomplete'"
            ." WHEN journeys.id IS NOT NULL THEN 'awaiting_documentation'"
            ." WHEN profiles.id IS NOT NULL THEN '{$profileOnly}'"
            .' WHEN NOT '.$grant(CandidateRole::CODE).' OR '.$grant(AdminAccess::ADMIN_ROLE)." OR {$judge} THEN 'not_required'"
            ." ELSE 'no_basic_information' END";

        return [$sql, [$now, $now]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<mixed>  $progressBindings
     */
    private function filter(Builder $query, array $filters, ?int $cycleId, string $progressSql, array $progressBindings): void
    {
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $like = '%'.addcslashes(trim((string) $filters['q']), '\\%_').'%';
            $query->where(fn (Builder $search) => $search->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like));
        }
        if (! empty($filters['state'])) {
            $query->where('users.status', $filters['state'] === 'disabled' ? 'disabled' : 'active');
        }
        if (! empty($filters['verified'])) {
            $filters['verified'] === 'verified' ? $query->whereNotNull('users.email_verified_at') : $query->whereNull('users.email_verified_at');
        }
        if (! empty($filters['role'])) {
            $now = now();
            match ($filters['role']) {
                'judge' => $query->whereExists(fn (Builder $grant) => $grant->selectRaw('1')->from('cycle_user_roles')
                    ->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')
                    ->whereColumn('cycle_user_roles.user_id', 'users.id')->where('roles.code', AdminAccess::JUDGE_ROLE)
                    // Cycle-scoped: a judge of the selected cycle.
                    ->where('cycle_user_roles.cycle_id', $cycleId ?? 0)->whereNull('cycle_user_roles.revoked_at')
                    ->where('cycle_user_roles.starts_at', '<=', $now)
                    ->where(fn (Builder $window) => $window->whereNull('cycle_user_roles.ends_at')->orWhere('cycle_user_roles.ends_at', '>', $now))),
                default => $query->whereExists(fn (Builder $grant) => $grant->selectRaw('1')->from('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->whereColumn('user_roles.user_id', 'users.id')->where('roles.code', $filters['role'])->whereNull('user_roles.revoked_at')),
            };
        }
        if (! empty($filters['progress'])) {
            $query->whereRaw("({$progressSql}) = ?", [...$progressBindings, $filters['progress']]);
        }
    }

    /**
     * Current role grants of the page's accounts: platform grants not
     * revoked; cycle grants not revoked and inside their window, with the
     * cycle and (for a judge) the main domain of the current membership.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{platform: list<array<string, string>>, cycle: list<array<string, mixed>>}>
     */
    private function rolesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $now = now();
        $roles = [];

        DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $userIds)->whereNull('user_roles.revoked_at')
            ->orderBy('roles.code')->get(['user_roles.user_id', 'roles.code', 'roles.name_ar'])
            ->each(function (object $row) use (&$roles): void {
                $roles[(int) $row->user_id]['platform'][] = ['code' => $row->code, 'name' => $row->name_ar];
            });

        $domains = DB::table('committee_members')
            ->join('committees', 'committees.id', '=', 'committee_members.committee_id')
            ->join('cycle_domains', 'cycle_domains.id', '=', 'committees.cycle_domain_id')
            ->whereIn('committee_members.user_id', $userIds)->whereNull('committee_members.left_at')
            ->where('committees.type', 'judging')->where('committees.status', '<>', 'dissolved')
            ->get(['committee_members.user_id', 'committees.cycle_id', 'cycle_domains.name_ar'])
            ->mapWithKeys(fn (object $row): array => ["{$row->user_id}:{$row->cycle_id}" => $row->name_ar]);

        DB::table('cycle_user_roles')->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')
            ->join('award_cycles', 'award_cycles.id', '=', 'cycle_user_roles.cycle_id')
            ->whereIn('cycle_user_roles.user_id', $userIds)->whereNull('cycle_user_roles.revoked_at')
            ->where('cycle_user_roles.starts_at', '<=', $now)
            ->where(fn (Builder $window) => $window->whereNull('cycle_user_roles.ends_at')->orWhere('cycle_user_roles.ends_at', '>', $now))
            ->orderByDesc('award_cycles.year')->orderBy('roles.code')
            ->get(['cycle_user_roles.user_id', 'cycle_user_roles.cycle_id', 'roles.code', 'roles.name_ar', 'award_cycles.name_ar as cycle_name', 'award_cycles.year'])
            ->each(function (object $row) use (&$roles, $domains): void {
                $roles[(int) $row->user_id]['cycle'][] = [
                    'code' => $row->code,
                    'name' => $row->name_ar,
                    'cycle' => ['id' => (int) $row->cycle_id, 'name' => $row->cycle_name, 'year' => (int) $row->year],
                    'domain' => $domains->get("{$row->user_id}:{$row->cycle_id}"),
                ];
            });

        return array_map(fn (array $held): array => ['platform' => $held['platform'] ?? [], 'cycle' => $held['cycle'] ?? []], $roles);
    }

    /**
     * The Stage 1–3 indicators, from the same stored state as the overall label.
     *
     * @return array{state: string, stage1: string, stage2: string, stage3: string}
     */
    private function stages(object $row): array
    {
        $done = in_array($row->progress, ['ready', 'submitted'], true);

        return [
            'state' => $row->progress,
            'stage1' => $row->profile_id !== null ? 'complete' : 'none',
            'stage2' => $row->journey_id !== null ? 'complete' : 'none',
            'stage3' => $done ? 'complete' : ($row->journey_id !== null && (int) $row->documentation_revision > 0 ? 'incomplete' : 'none'),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string, year: int, stage_open: bool}|null
     */
    private function cycleMeta(?int $cycleId): ?array
    {
        $cycle = $cycleId === null ? null : DB::table('award_cycles')->where('id', $cycleId)->first(['id', 'code', 'name_ar', 'year']);

        return $cycle === null ? null : [
            'id' => (int) $cycle->id, 'code' => $cycle->code, 'name' => $cycle->name_ar, 'year' => (int) $cycle->year,
            // Whether candidates can change Stages 2–3 now; completed stages stay completed either way.
            'stage_open' => $this->cycles->stageOpenFor((int) $cycle->id),
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value, 'UTC')->toIso8601ZuluString();
    }
}
