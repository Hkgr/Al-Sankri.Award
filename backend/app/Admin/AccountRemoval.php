<?php

namespace App\Admin;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountRemoval
{
    public function __construct(private readonly AdminAccess $access, private readonly RemovalFiles $files) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, int $targetId): array
    {
        return DB::transaction(function () use ($actor, $targetId): array {
            $target = $this->lockAndAuthorize($actor, $targetId);
            $plan = $this->plan($target);

            return [
                'target' => ['id' => $targetId, 'name' => $target->name, 'email' => $target->email, 'roles' => $plan['roles']],
                'impact' => $plan['impact'], 'fingerprint' => $this->fingerprint($plan),
            ];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function remove(User $actor, int $targetId, array $input): array
    {
        $result = DB::transaction(function () use ($actor, $targetId, $input): array {
            User::withTrashed()->whereIn('id', [$actor->id, $targetId])->orderBy('id')->lockForUpdate()->get();
            if (! $actor->fresh()?->isActive() || ! $this->access->can($actor, AdminAccess::REMOVE_ACCOUNTS)) {
                throw new AuthorizationException;
            }
            $done = DB::table('account_removals')->where('id', $input['operation_id'])->first();
            if ($done !== null) {
                if ((int) $done->actor_user_id !== $actor->id || (int) $done->target_user_id !== $targetId || $done->mode !== $input['mode']) {
                    throw new RefusedChange('removal_changed');
                }

                return ['already_completed' => true, 'mode' => $done->mode];
            }
            $target = $this->lockAndAuthorize($actor, $targetId);
            $plan = $this->plan($target);
            if (! hash_equals($this->fingerprint($plan), $input['fingerprint'])) {
                throw new RefusedChange('removal_changed');
            }
            if ($input['mode'] === 'purge' && ! hash_equals($target->email, (string) ($input['confirmation'] ?? ''))) {
                throw ValidationException::withMessages(['confirmation' => 'اكتب البريد الإلكتروني للحساب كاملاً لتأكيد الحذف النهائي.']);
            }
            $this->revokeAccess($target);
            $this->retireMemberships($plan, $actor);
            if ($input['mode'] === 'purge') {
                $this->purge($target, $plan);
            } else {
                $target->forceFill([
                    'name' => 'حساب محذوف', 'email' => 'removed-'.Str::uuid().'@deleted.invalid',
                    'phone' => null, 'phone_verified_at' => null, 'password' => null, 'remember_token' => null,
                    'status' => User::STATUS_DISABLED, 'disabled_at' => now(), 'deleted_at' => now(),
                    'disabled_reason' => 'account_removed',
                ])->save();
            }
            DB::table('account_removals')->insert([
                'id' => $input['operation_id'], 'actor_user_id' => $actor->id, 'target_user_id' => $targetId,
                'mode' => $input['mode'], 'impact' => json_encode($plan['impact']), 'completed_at' => now(),
            ]);
            AuditLog::record('account.removed', $actor->id, 'user', $targetId, ['mode' => $input['mode'], 'impact' => $plan['impact']]);
            DB::afterCommit(function (): void {
                try {
                    $this->files->drain();
                } catch (\Throwable) {
                    // The transaction committed; the durable queue remains retryable.
                }
            });

            return ['already_completed' => false, 'mode' => $input['mode']];
        }, 3);
        $result['pending_file_deletions'] = DB::table('removed_account_files')->whereNull('completed_at')->count();

        return $result;
    }

    private function lockAndAuthorize(User $actor, int $targetId): User
    {
        $accounts = User::withTrashed()->whereIn('id', [$actor->id, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (! isset($accounts[$actor->id]) || ! $accounts[$actor->id]->isActive() || ! $this->access->can($accounts[$actor->id], AdminAccess::REMOVE_ACCOUNTS)) {
            throw new AuthorizationException;
        }
        $target = $accounts[$targetId] ?? null;
        if ($target === null) {
            throw new RefusedChange('removal_target_missing');
        }
        // Protect every administrative role, including historical grants and custom platform roles.
        $staff = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $targetId)->where('roles.code', '<>', 'candidate')->exists();
        $candidate = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $targetId)->where('roles.code', 'candidate')->exists();
        $judge = DB::table('committee_members')->where('user_id', $targetId)->exists()
            || DB::table('cycle_user_roles')->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')
                ->where('cycle_user_roles.user_id', $targetId)->where('roles.code', 'judge')->exists();
        if ($target->trashed() || $actor->id === $targetId || $staff || $this->access->isAdmin($target) || (! $candidate && ! $judge)) {
            throw new AuthorizationException('لا يمكن حذف حساب إداري أو حسابك الحالي عبر هذا الإجراء.');
        }
        DB::table('award_cycles')->orderBy('id')->lockForUpdate()->get(['id']);

        return $target;
    }

    public function canTarget(int $id): bool
    {
        $staff = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $id)->where('roles.code', '<>', 'candidate')->exists();
        $candidate = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $id)->where('roles.code', 'candidate')->exists();
        $judge = DB::table('committee_members')->where('user_id', $id)->exists()
            || DB::table('cycle_user_roles')->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')->where('user_id', $id)->where('roles.code', 'judge')->exists();

        return ! $staff && ($candidate || $judge) && User::query()->whereKey($id)->exists();
    }

    /** @return array<string, mixed> */
    private function plan(User $target): array
    {
        $id = $target->id;
        // Match referral/approval lock order: committees before applications.
        $ownedDomains = $this->ownedApplications($id)->pluck('cycle_domain_id');
        DB::table('committees')->whereIn('cycle_domain_id', $ownedDomains)->orWhereIn('id', DB::table('committee_members')->where('user_id', $id)->select('committee_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        $applications = $this->ownedApplications($id)->orderBy('id')->lockForUpdate()->get();
        $appIds = $applications->pluck('id')->all();
        $versions = DB::table('application_versions')->whereIn('application_id', $appIds)->orderBy('id')->get();
        $members = DB::table('committee_members')->where('user_id', $id)->orderBy('id')->lockForUpdate()->get();
        $committees = DB::table('committees')->whereIn('id', $members->pluck('committee_id'))->orderBy('id')->lockForUpdate()->get();
        $assignments = DB::table('judging_assignments')->whereIn('application_id', $appIds)
            ->orWhereIn('committee_member_id', $members->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        DB::table('applications')->whereIn('id', $assignments->pluck('application_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        $evaluations = DB::table('evaluations')->whereIn('judging_assignment_id', $assignments->pluck('id'))->get();
        $scores = DB::table('evaluation_scores')->whereIn('evaluation_id', $evaluations->pluck('id'))->orderBy('id')->get();
        $appeals = DB::table('appeals')->whereIn('application_id', $appIds)->orWhere('submitted_by', $id)->get();
        $stories = DB::table('published_stories')->whereIn('application_version_id', $versions->pluck('id'))->get();
        $links = DB::table('file_links')->whereIn('application_id', $appIds)->orWhereIn('application_version_id', $versions->pluck('id'))
            ->orWhereIn('appeal_id', $appeals->pluck('id'))->orWhereIn('published_story_id', $stories->pluck('id'))->get();
        $fileCandidates = DB::table('files')->where('uploaded_by', $id)->orWhereIn('id', $links->pluck('file_id'))->orderBy('id')->lockForUpdate()->get();
        $files = $fileCandidates->filter(fn (object $file): bool => ! DB::table('file_links')->where('file_id', $file->id)->whereNotIn('id', $links->pluck('id'))->exists());
        do {
            $before = $files->count();
            $files = $files->filter(fn (object $file): bool => ! DB::table('files')->where('source_file_id', $file->id)->whereNotIn('id', $files->pluck('id'))->exists());
        } while ($files->count() !== $before);
        $affectedApps = $assignments->pluck('application_id')->merge($appIds)->unique()->values()->all();
        $results = DB::table('award_results')->whereIn('application_id', $affectedApps)->orderBy('id')->lockForUpdate()->get();
        $affectedStories = DB::table('published_stories')->whereIn('application_version_id', DB::table('application_versions')->whereIn('application_id', $affectedApps)->select('id'))->orderBy('id')->lockForUpdate()->get();
        $roles = [];
        if (DB::table('candidate_profiles')->where('user_id', $id)->exists() || DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $id)->where('roles.code', 'candidate')->exists()) {
            $roles[] = 'candidate';
        }
        if ($members->isNotEmpty() || DB::table('cycle_user_roles')->join('roles', 'roles.id', '=', 'cycle_user_roles.role_id')->where('user_id', $id)->where('roles.code', 'judge')->exists()) {
            $roles[] = 'judge';
        }
        $impact = [
            'applications' => count($appIds), 'versions' => $versions->count(), 'file_links' => $links->count(),
            'files' => $files->count(), 'shared_files' => $fileCandidates->count() - $files->count(),
            'assignments' => $assignments->count(), 'evaluations' => $evaluations->count(),
            'scores' => $scores->count(),
            'committees' => $committees->whereIn('id', $members->whereNull('left_at')->pluck('committee_id'))->where('status', '<>', 'dissolved')->count(), 'results' => $results->count(),
            'published_results' => $results->where('status', 'published')->count(), 'stories' => $stories->count(),
            'published_stories' => $affectedStories->where('status', 'published')->count(), 'appeals' => $appeals->count(),
        ];

        return compact('applications', 'versions', 'members', 'committees', 'assignments', 'evaluations', 'scores', 'appeals', 'stories', 'affectedStories', 'links', 'files', 'fileCandidates', 'results', 'roles', 'impact')
            + ['user' => $target->getAttributes(), 'appIds' => $appIds, 'affectedApps' => $affectedApps];
    }

    /** @param array<string, mixed> $plan */
    private function fingerprint(array $plan): string
    {
        return hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Explicit candidate ownership wins over a legacy creator attribution. */
    private function ownedApplications(int $id): Builder
    {
        return DB::table('applications')->where(function (Builder $owned) use ($id): void {
            $owned->whereIn('id', DB::table('candidate_nominations')->where('user_id', $id)->select('application_id'))
                ->orWhereIn('applicant_id', DB::table('candidate_profiles')->where('user_id', $id)->select('applicant_id'))
                ->orWhere(function (Builder $legacy) use ($id): void {
                    $legacy->where('created_by', $id)
                        ->whereNotExists(fn (Builder $other) => $other->selectRaw('1')->from('candidate_nominations')->whereColumn('candidate_nominations.application_id', 'applications.id')->where('user_id', '<>', $id))
                        ->whereNotExists(fn (Builder $other) => $other->selectRaw('1')->from('candidate_profiles')->whereColumn('candidate_profiles.applicant_id', 'applications.applicant_id')->where('user_id', '<>', $id))
                        ->whereNotExists(fn (Builder $other) => $other->selectRaw('1')->from('applicants')->whereColumn('applicants.id', 'applications.applicant_id')->whereNotNull('representative_user_id')->where('representative_user_id', '<>', $id));
                });
        });
    }

    private function revokeAccess(User $target): void
    {
        DB::table('sessions')->where('user_id', $target->id)->delete();
        DB::table('password_reset_tokens')->where('email', $target->email)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $target->id)->delete();
        DB::table('oauth_identities')->where('user_id', $target->id)->delete();
    }

    /** @param array<string, mixed> $plan */
    private function retireMemberships(array $plan, User $actor): void
    {
        DB::table('committee_members')->whereIn('id', $plan['members']->whereNull('left_at')->pluck('id'))->update(['left_at' => now(), 'updated_at' => now()]);
        DB::table('committees')->whereIn('id', $plan['members']->whereNull('left_at')->pluck('committee_id'))->where('status', '<>', 'dissolved')
            ->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
        foreach (['user_roles', 'cycle_user_roles'] as $table) {
            DB::table($table)->where('user_id', $plan['user']['id'])->whereNull('revoked_at')->update([
                'revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now(),
            ] + ($table === 'cycle_user_roles' ? ['revocation_reason' => 'account_removed'] : []));
        }
    }

    /** @param array<string, mixed> $plan */
    private function purge(User $target, array $plan): void
    {
        $appIds = $plan['appIds'];
        $versionIds = $plan['versions']->pluck('id')->all();
        $assignmentIds = $plan['assignments']->pluck('id')->all();
        $evaluationIds = $plan['evaluations']->pluck('id')->all();
        DB::table('published_stories')->whereIn('application_version_id', DB::table('application_versions')->whereIn('application_id', $plan['affectedApps'])->select('id'))
            ->where('status', 'published')->update(['status' => 'unpublished', 'updated_at' => now()]);
        DB::table('award_results')->whereIn('id', $plan['results']->pluck('id'))->update(['status' => 'withdrawn', 'score_summary' => null, 'published_at' => null, 'updated_at' => now()]);
        $ownedResults = $plan['results']->whereIn('application_id', $appIds)->pluck('id');
        DB::table('result_ratifications')->whereIn('result_id', $ownedResults)->delete();
        // Remove owned leaves first. Keep only empty version anchors needed by a surviving result chain.
        foreach ($plan['results']->whereIn('id', $ownedResults)->sortByDesc('decision_version') as $result) {
            if (DB::table('award_results')->where('supersedes_result_id', $result->id)->exists()) {
                DB::table('award_results')->where('id', $result->id)->update([
                    'application_id' => null, 'application_version_id' => null, 'decision_type' => 'withhold', 'technical_reason' => 'account_removed',
                ]);
            } else {
                DB::table('award_results')->where('id', $result->id)->delete();
            }
        }
        DB::table('file_links')->whereIn('id', $plan['links']->pluck('id'))->delete();
        DB::table('published_stories')->whereIn('id', $plan['stories']->pluck('id'))->delete();
        DB::table('application_consents')->whereIn('application_version_id', $versionIds)->delete();
        DB::table('appeal_decisions')->whereIn('appeal_id', $plan['appeals']->pluck('id'))->delete();
        DB::table('appeals')->whereIn('id', $plan['appeals']->pluck('id'))->delete();
        DB::table('evaluation_scores')->whereIn('evaluation_id', $evaluationIds)->delete();
        DB::table('evaluations')->whereIn('id', $evaluationIds)->delete();
        DB::table('conflict_declarations')->whereIn('application_id', $appIds)->orWhere('user_id', $target->id)->delete();
        DB::table('conflict_declarations')->whereIn('judging_assignment_id', $assignmentIds)->update(['judging_assignment_id' => null]);
        DB::table('judging_assignments')->whereIn('id', $assignmentIds)->delete();
        DB::table('committee_members')->where('user_id', $target->id)->delete();
        DB::table('eligibility_reviews')->whereIn('application_version_id', $versionIds)->delete();
        DB::table('application_versions')->whereIn('id', $versionIds)->update(['completion_request_id' => null, 'submission_type' => 'initial']);
        DB::table('completion_requests')->whereIn('application_id', $appIds)->delete();
        DB::table('candidate_nominations')->where('user_id', $target->id)->delete();
        DB::table('applications')->whereIn('id', $appIds)->update([
            'status' => 'draft', 'current_version_id' => null, 'withdrawn_at' => null,
            'review_status' => null, 'review_source' => null, 'review_decided_by' => null,
            'review_decided_at' => null, 'review_reason' => null, 'review_committee_id' => null,
        ]);
        DB::table('application_versions')->whereIn('id', $versionIds)->delete();
        foreach (['application_documentation_links', 'application_locations', 'application_partnerships'] as $table) {
            DB::table($table)->whereIn('application_id', $appIds)->delete();
        }
        DB::table('applications')->whereIn('id', $appIds)->delete();
        DB::table('account_policy_acceptances')->where('user_id', $target->id)->delete();
        $applicants = DB::table('candidate_profiles')->where('user_id', $target->id)->pluck('applicant_id')->merge($plan['applications']->pluck('applicant_id'))->unique();
        DB::table('candidate_profiles')->where('user_id', $target->id)->delete();
        foreach ($applicants as $applicantId) {
            if (DB::table('applications')->where('applicant_id', $applicantId)->exists() || DB::table('candidate_profiles')->where('applicant_id', $applicantId)->exists()
                || DB::table('applicant_members')->where('applicant_id', $applicantId)->whereNotNull('user_id')->where('user_id', '<>', $target->id)->exists()) {
                continue;
            }
            foreach (['applicant_individual_details', 'applicant_institution_details', 'applicant_social_links', 'applicant_members'] as $table) {
                DB::table($table)->where('applicant_id', $applicantId)->delete();
            }
            DB::table('applicants')->where('id', $applicantId)->delete();
        }
        $fileIds = $plan['files']->pluck('id')->all();
        DB::table('files')->whereIn('id', $fileIds)->update(['source_file_id' => null]);
        foreach ($plan['files'] as $file) {
            DB::table('removed_account_files')->insertOrIgnore([
                'storage_disk' => $file->storage_disk, 'storage_path' => $file->storage_path, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('files')->whereIn('id', $fileIds)->delete();
        foreach (['user_roles', 'cycle_user_roles'] as $table) {
            DB::table($table)->where('user_id', $target->id)->delete();
        }
        DB::table('audit_logs')->where('actor_user_id', $target->id)
            ->orWhere(fn (Builder $q) => $q->where('subject_type', 'user')->where('subject_id', $target->id))
            ->orWhere(fn (Builder $q) => $q->whereIn('subject_type', ['application', 'applications'])->whereIn('subject_id', $appIds))->delete();
        foreach (['application_version' => $versionIds, 'evaluation' => $evaluationIds, 'file' => $fileIds, 'committee_member' => $plan['members']->pluck('id')->all()] as $type => $ids) {
            DB::table('audit_logs')->where('subject_type', $type)->whereIn('subject_id', $ids)->delete();
        }
        $this->preserveSharedAttributions($target->id);
        DB::table('users')->where('id', $target->id)->delete();
    }

    /** Replace attribution only; shared entities are never deleted merely for referencing the account. */
    private function preserveSharedAttributions(int $targetId): void
    {
        $archiveId = null;
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] !== 'users' || count($key['columns']) !== 1) {
                    continue;
                }
                $column = $key['columns'][0];
                if (! DB::table($table['name'])->where($column, $targetId)->exists()) {
                    continue;
                }
                $archiveId ??= DB::table('users')->insertGetId([
                    'name' => 'حساب محذوف', 'email' => 'archive-'.Str::uuid().'@deleted.invalid', 'password' => null,
                    'status' => User::STATUS_DISABLED, 'disabled_at' => now(), 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table($table['name'])->where($column, $targetId)->update([$column => $archiveId]);
            }
        }
    }
}
