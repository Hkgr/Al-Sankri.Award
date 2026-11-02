<?php

namespace Tests\Feature;

use App\Admin\AccountRemoval;
use App\Admin\AdminAccess;
use App\Admin\ApplicationReviews;
use App\Admin\CycleSchedule;
use App\Admin\JudgingCommittees;
use App\Admin\RemovalFiles;
use App\Candidates\AccountContext;
use App\Candidates\AwardTerms;
use App\Judging\CommitteeDecision;
use App\Judging\JudgePortal;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('candidate_evidence');
        $this->admin = User::factory()->create();
        app(AdminAccess::class)->grantAdmin($this->admin);
        $this->candidate = User::factory()->candidate()->create();
    }

    public function test_only_authorized_admins_can_preview_or_remove_and_admin_accounts_are_protected(): void
    {
        $this->actingAs($this->candidate)->getJson($this->url($this->candidate).'/removal-preview')->assertForbidden();
        $this->postJson($this->url($this->candidate).'/remove', [])->assertForbidden();
        $this->actingAs($this->admin)->getJson($this->url($this->admin).'/removal-preview')->assertForbidden();
        $otherAdmin = User::factory()->candidate()->create();
        app(AdminAccess::class)->grantAdmin($otherAdmin);
        $this->getJson($this->url($otherAdmin).'/removal-preview')->assertForbidden();
        DB::table('user_roles')->where('user_id', $otherAdmin->id)->where('role_id', DB::table('roles')->where('code', 'award_admin')->value('id'))
            ->update(['revoked_at' => now(), 'revoked_by' => $this->admin->id]);
        $this->getJson($this->url($otherAdmin).'/removal-preview')->assertForbidden();
        $permission = DB::table('permissions')->where('code', AdminAccess::REMOVE_ACCOUNTS)->value('id');
        DB::table('role_permissions')->where('permission_id', $permission)->delete();
        $this->getJson($this->url($this->candidate).'/removal-preview')->assertForbidden();
    }

    public function test_retention_preserves_history_and_revokes_access_without_transferring_it_to_a_new_registration(): void
    {
        [$app, $version, $file] = $this->participation($this->candidate);
        $email = $this->candidate->email;
        DB::table('sessions')->insert(['id' => 'candidate-session', 'user_id' => $this->candidate->id, 'payload' => 'test', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'old-reset', 'created_at' => now()]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $this->candidate->id, 'name' => 'test', 'token' => hash('sha256', 'old-token')]);
        DB::table('oauth_identities')->insert(['user_id' => $this->candidate->id, 'provider' => 'google', 'provider_subject' => 'old-google-subject', 'linked_at' => now()]);
        $this->actingAs($this->admin);
        $input = $this->input($this->candidate, 'retain');
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $app]);
        $this->assertDatabaseHas('application_versions', ['id' => $version]);
        $this->assertDatabaseHas('files', ['id' => $file]);
        Storage::disk('candidate_evidence')->assertExists("{$file}.pdf");
        $this->assertSame('حساب محذوف', $this->candidate->fresh()->name);
        $this->assertFalse($this->candidate->fresh()->isActive());
        $this->assertNull(User::query()->find($this->candidate->id), 'Later mutations cannot lock a removed account through normal model queries.');
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->candidate->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->candidate->id]);
        $this->assertDatabaseMissing('oauth_identities', ['provider_subject' => 'old-google-subject']);
        $this->getJson('/api/admin/users')->assertOk()->assertJsonMissing(['id' => $this->candidate->id, 'name' => 'حساب محذوف']);
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertOk()->assertJsonPath('data.already_completed', true);
        $this->assertSame(1, DB::table('account_removals')->count());
        $this->postJson($this->url($this->candidate).'/remove', array_replace($input, ['mode' => 'purge', 'confirmation' => $email]))->assertConflict();
        $newAccount = User::factory()->candidate()->create(['email' => $email]);
        $this->assertNotSame($newAccount->id, $this->candidate->id);
        $this->assertDatabaseMissing('candidate_profiles', ['user_id' => $newAccount->id]);
        $this->actingAs($this->candidate->fresh())->getJson('/api/user')->assertForbidden();
    }

    public function test_purge_removes_owned_records_but_preserves_other_accounts_applications_and_shared_files(): void
    {
        [$app, $version, $private] = $this->participation($this->candidate);
        $other = User::factory()->candidate()->create();
        [$otherApp, $otherVersion, $shared] = $this->participation($other);
        // Attribution alone does not transfer the other candidate's ownership.
        DB::table('applications')->where('id', $otherApp)->update(['created_by' => $this->candidate->id]);
        DB::table('files')->where('id', $shared)->update(['uploaded_by' => $this->candidate->id]);
        DB::table('file_links')->insert(['file_id' => $shared, 'application_id' => $app, 'purpose' => 'evidence']);
        $this->actingAs($this->admin);
        $input = $this->input($this->candidate, 'purge');
        $this->postJson($this->url($this->candidate).'/remove', $input + ['confirmation' => 'wrong'])->assertUnprocessable();
        $input['confirmation'] = $this->candidate->email;
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $this->candidate->id]);
        $this->assertDatabaseMissing('applications', ['id' => $app]);
        $this->assertDatabaseMissing('application_versions', ['id' => $version]);
        $this->assertDatabaseMissing('files', ['id' => $private]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('applications', ['id' => $otherApp]);
        $this->assertDatabaseHas('application_versions', ['id' => $otherVersion]);
        $this->assertDatabaseHas('files', ['id' => $shared]);
        $this->assertDatabaseHas('file_links', ['file_id' => $shared, 'application_id' => $otherApp]);
        app(RemovalFiles::class)->drain();
        Storage::disk('candidate_evidence')->assertMissing("{$private}.pdf");
        Storage::disk('candidate_evidence')->assertExists("{$shared}.pdf");
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertOk()->assertJsonPath('data.already_completed', true);
    }

    public function test_a_changed_preview_is_rejected_without_partial_deletion(): void
    {
        [$app, , $file] = $this->participation($this->candidate);
        $this->actingAs($this->admin);
        $input = $this->input($this->candidate, 'purge');
        $input['confirmation'] = $this->candidate->email;
        DB::table('applications')->where('id', $app)->update(['title' => 'Changed in another request']);
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertConflict()->assertJsonPath('code', 'removal_changed');
        $this->assertTrue($this->candidate->fresh()->isActive());
        $this->assertDatabaseHas('applications', ['id' => $app]);
        Storage::disk('candidate_evidence')->assertExists("{$file}.pdf");
        $this->assertDatabaseCount('account_removals', 0);
    }

    public function test_file_deletion_failure_is_durable_retryable_and_never_targets_referenced_paths(): void
    {
        [, , $file] = $this->participation($this->candidate);
        DB::table('removed_account_files')->insert(['storage_disk' => 'candidate_evidence', 'storage_path' => "{$file}.pdf", 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, app(RemovalFiles::class)->drain());
        Storage::disk('candidate_evidence')->assertExists("{$file}.pdf");
        DB::table('removed_account_files')->insert(['storage_disk' => 'not_configured_disk', 'storage_path' => 'private.pdf', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2, app(RemovalFiles::class)->drain());
        $this->assertDatabaseHas('removed_account_files', ['storage_disk' => 'not_configured_disk', 'attempts' => 1, 'last_error' => 'storage_unavailable', 'completed_at' => null]);
    }

    public function test_failure_before_commit_rolls_back_every_record_and_never_deletes_file_bytes(): void
    {
        [$app, $version, $file] = $this->participation($this->candidate);
        $this->actingAs($this->admin);
        $input = $this->input($this->candidate, 'purge') + ['confirmation' => $this->candidate->email];
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'removed_account_files')) {
                $armed = false;
                throw new \RuntimeException('injected failure before commit');
            }
        });
        try {
            app(AccountRemoval::class)->remove($this->admin, $this->candidate->id, $input);
            $this->fail('A transaction failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected failure before commit', $error->getMessage());
        }
        $this->assertFalse($armed);
        $this->assertTrue($this->candidate->fresh()->isActive());
        $this->assertDatabaseHas('applications', ['id' => $app]);
        $this->assertDatabaseHas('application_versions', ['id' => $version]);
        $this->assertDatabaseHas('files', ['id' => $file]);
        $this->assertDatabaseCount('account_removals', 0);
        $this->assertDatabaseCount('removed_account_files', 0);
        Storage::disk('candidate_evidence')->assertExists("{$file}.pdf");
    }

    public function test_retained_judge_marks_stay_historical_and_a_reconstituted_committee_recomputes_its_average(): void
    {
        [$app, , , $committee, $judges] = $this->judgedParticipation();
        $this->actingAs($this->admin);
        $input = $this->input($judges[0], 'retain');
        $this->postJson($this->url($judges[0]).'/remove', $input)->assertOk();
        $this->assertDatabaseCount('evaluations', 3);
        $decision = app(CommitteeDecision::class)->forApplication(Application::findOrFail($app));
        $this->assertFalse($decision['ready']);
        $this->assertNull($decision['decision']);
        $this->assertSame(2, $decision['received']);
        $this->assertSame('حساب محذوف', $decision['scores'][0]['name']);
        $this->assertTrue($decision['scores'][0]['historical']);
        $this->assertSame('60', $decision['scores'][0]['score']);
        $replacement = User::factory()->create();
        $this->addJudge($committee, $replacement);
        $cycle = (int) DB::table('applications')->where('id', $app)->value('cycle_id');
        app(JudgingCommittees::class)->approve($this->admin, $cycle, 'culture_and_arts');
        $this->assertSame(JudgePortal::SENT, app(JudgePortal::class)->send($replacement, $app, '100', 'replacement notes', 'recommendations', false));
        $decision = app(CommitteeDecision::class)->forApplication(Application::findOrFail($app));
        $this->assertSame('90', $decision['decision']); // 80 + 90 + 100, never the former 60.
    }

    public function test_purging_a_judge_withdraws_affected_results_and_preserves_other_judges_and_the_candidate(): void
    {
        [$app, $version, $file, $committee, $judges, $result, $story] = $this->judgedParticipation();
        [$ownedApp, $ownedVersion, $ownedFile] = $this->participation($judges[0]);
        $this->actingAs($this->admin);
        $input = $this->input($judges[0], 'purge') + ['confirmation' => $judges[0]->email];
        $this->postJson($this->url($judges[0]).'/remove', $input)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $judges[0]->id]);
        $this->assertDatabaseMissing('applications', ['id' => $ownedApp]);
        $this->assertDatabaseMissing('application_versions', ['id' => $ownedVersion]);
        $this->assertDatabaseMissing('files', ['id' => $ownedFile]);
        $this->assertDatabaseCount('evaluations', 2);
        $this->assertDatabaseCount('evaluation_scores', 2);
        $this->assertDatabaseHas('users', ['id' => $this->candidate->id]);
        $this->assertDatabaseHas('applications', ['id' => $app]);
        $this->assertDatabaseHas('application_versions', ['id' => $version]);
        $this->assertDatabaseHas('files', ['id' => $file]);
        $this->assertDatabaseHas('committees', ['id' => $committee, 'status' => 'draft']);
        $this->assertDatabaseHas('award_results', ['id' => $result, 'status' => 'withdrawn', 'score_summary' => null]);
        $this->assertDatabaseHas('published_stories', ['id' => $story, 'status' => 'unpublished']);
        $decision = app(CommitteeDecision::class)->forApplication(Application::findOrFail($app));
        $this->assertNull($decision['decision']);
        $this->assertCount(2, $decision['scores']);
    }

    public function test_a_score_committed_after_preview_requires_a_fresh_confirmation(): void
    {
        [, , , , $judges] = $this->judgedParticipation();
        $this->actingAs($this->admin);
        $input = $this->input($judges[0], 'purge') + ['confirmation' => $judges[0]->email];
        DB::table('evaluation_scores')->orderBy('id')->limit(1)->update(['score' => 61]);
        $this->postJson($this->url($judges[0]).'/remove', $input)->assertConflict();
        $this->assertDatabaseCount('evaluations', 3);
        $this->assertTrue($judges[0]->fresh()->isActive());
    }

    public function test_purging_a_candidate_removes_its_published_history_without_removing_judges(): void
    {
        [$app, $version, , , $judges, $result, $story] = $this->judgedParticipation();
        $this->actingAs($this->admin);
        $input = $this->input($this->candidate, 'purge') + ['confirmation' => $this->candidate->email];
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertOk();
        foreach (['applications' => $app, 'application_versions' => $version, 'award_results' => $result, 'published_stories' => $story] as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        $this->assertDatabaseCount('evaluations', 0);
        foreach ($judges as $judge) {
            $this->assertDatabaseHas('users', ['id' => $judge->id]);
        }
    }

    public function test_a_shared_result_chain_keeps_only_an_empty_anchor_for_the_removed_application(): void
    {
        [, , , , , $result] = $this->judgedParticipation();
        $other = User::factory()->candidate()->create();
        [$otherApp, $otherVersion] = $this->participation($other);
        $next = (array) DB::table('award_results')->where('id', $result)->first();
        unset($next['id'], $next['is_current']);
        DB::table('award_results')->where('id', $result)->update(['status' => 'superseded']);
        $next['application_id'] = $otherApp;
        $next['application_version_id'] = $otherVersion;
        $next['decision_version'] = 2;
        $next['supersedes_result_id'] = $result;
        $successor = DB::table('award_results')->insertGetId($next);
        $this->actingAs($this->admin);
        $input = $this->input($this->candidate, 'purge') + ['confirmation' => $this->candidate->email];
        $this->postJson($this->url($this->candidate).'/remove', $input)->assertOk();
        $this->assertDatabaseHas('award_results', ['id' => $result, 'application_id' => null, 'application_version_id' => null, 'technical_reason' => 'account_removed', 'score_summary' => null, 'status' => 'withdrawn']);
        $this->assertDatabaseHas('award_results', ['id' => $successor, 'application_id' => $otherApp, 'status' => 'published', 'supersedes_result_id' => $result]);
    }

    /** @return array{int, int, int, int, array<User>, int, int} */
    private function judgedParticipation(): array
    {
        [$app, $version, $file] = $this->participation($this->candidate);
        $application = DB::table('applications')->where('id', $app)->first();
        app(CycleSchedule::class)->ensureJudgingStage((int) $application->cycle_id, $this->admin);
        [, $committee] = app(JudgingCommittees::class)->create($this->admin, (int) $application->cycle_id, 'culture_and_arts', 'Test committee');
        $judges = [];
        foreach ([60, 80, 90] as $mark) {
            $judge = User::factory()->create();
            $this->addJudge($committee, $judge);
            $judges[] = $judge;
        }
        app(JudgingCommittees::class)->approve($this->admin, (int) $application->cycle_id, 'culture_and_arts');
        $this->assertSame('referred', app(ApplicationReviews::class)->refer($this->admin, $app, $committee));
        foreach ($judges as $index => $judge) {
            $this->assertSame(JudgePortal::SENT, app(JudgePortal::class)->send($judge, $app, (string) [60, 80, 90][$index], 'notes', 'recommendations', false));
        }
        $slot = DB::table('award_slots')->insertGetId(['cycle_domain_id' => $application->cycle_domain_id, 'code' => 'test-slot', 'name_ar' => 'اختبار', 'slot_number' => 1]);
        $result = DB::table('award_results')->insertGetId(['cycle_id' => $application->cycle_id, 'cycle_domain_id' => $application->cycle_domain_id, 'award_slot_id' => $slot, 'application_id' => $app, 'application_version_id' => $version, 'decision_version' => 1, 'decision_type' => 'grant', 'recommending_committee_id' => $committee, 'technical_reason' => 'Test decision', 'score_summary' => '{"average":76.67}', 'status' => 'published', 'recommended_at' => now(), 'published_at' => now()]);
        $policy = app(AwardTerms::class)->current((int) $application->cycle_id);
        $consent = DB::table('application_consents')->insertGetId(['application_version_id' => $version, 'policy_version_id' => $policy->id, 'accepted_by' => $this->candidate->id, 'accepted_at' => now()]);
        $story = DB::table('published_stories')->insertGetId(['application_version_id' => $version, 'publication_consent_id' => $consent, 'public_slug' => 'test-story', 'public_title' => 'Test story', 'public_applicant_name' => 'Test candidate', 'public_summary' => 'Test summary', 'public_story' => 'Test story text', 'status' => 'published', 'approved_by' => $this->admin->id, 'published_at' => now()]);

        return [$app, $version, $file, $committee, $judges, $result, $story];
    }

    private function addJudge(int $committee, User $judge): void
    {
        $cycle = DB::table('committees')->where('id', $committee)->value('cycle_id');
        DB::table('committee_members')->insert(['committee_id' => $committee, 'user_id' => $judge->id, 'position' => 'member', 'joined_at' => now()]);
        DB::table('cycle_user_roles')->insert(['cycle_id' => $cycle, 'user_id' => $judge->id, 'role_id' => DB::table('roles')->where('code', 'judge')->value('id'), 'assigned_by' => $this->admin->id, 'starts_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function input(User $target, string $mode): array
    {
        $preview = $this->getJson($this->url($target).'/removal-preview')->assertOk()->json('data');

        return ['operation_id' => (string) Str::uuid(), 'mode' => $mode, 'fingerprint' => $preview['fingerprint'], 'account_context' => AccountContext::for($this->admin)];
    }

    private function url(User $target): string
    {
        return '/api/admin/users/'.$target->id;
    }

    /** @return array{int, int, int} */
    private function participation(User $user): array
    {
        if (! DB::table('award_cycles')->exists()) {
            $this->artisan('nomination:setup', [
                'cycle' => 'removal-test', '--actor' => $this->admin->email, '--create-cycle' => true,
                '--name' => 'دورة اختبار', '--award-name' => 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع',
                '--year' => 2026, '--timezone' => 'Asia/Damascus', '--country' => 'SY', '--open-domains' => true, '--enable' => true, '--publish-cycle' => true,
            ])->assertSuccessful();
        }
        $domain = DB::table('cycle_domains')->first();
        $applicant = DB::table('applicants')->insertGetId(['type' => 'individual', 'name_ar' => $user->name, 'representative_user_id' => $user->id, 'status' => 'active']);
        $app = DB::table('applications')->insertGetId(['cycle_id' => $domain->cycle_id, 'cycle_domain_id' => $domain->id, 'applicant_id' => $applicant, 'created_by' => $user->id, 'title' => 'اختبار', 'status' => 'draft']);
        $version = DB::table('application_versions')->insertGetId(['application_id' => $app, 'version_number' => 1, 'submission_type' => 'initial', 'submitted_by' => $user->id, 'submitted_at' => now(), 'data_snapshot' => '{}', 'form_snapshot' => '{}', 'content_hash' => str_repeat('a', 64)]);
        DB::table('applications')->where('id', $app)->update(['status' => 'submitted', 'review_status' => 'pending', 'current_version_id' => $version, 'first_submitted_at' => now()]);
        $profile = DB::table('candidate_profiles')->insertGetId(['user_id' => $user->id, 'applicant_id' => $applicant, 'profile_type' => 'individual', 'data_accuracy_declared_at' => now(), 'completed_at' => now(), 'created_at' => now()]);
        DB::table('candidate_nominations')->insert(['user_id' => $user->id, 'candidate_profile_id' => $profile, 'applicant_id' => $applicant, 'cycle_id' => $domain->cycle_id, 'application_id' => $app, 'details_confirmed_at' => now(), 'created_at' => now()]);
        $file = DB::table('files')->insertGetId(['uploaded_by' => $user->id, 'storage_disk' => 'candidate_evidence', 'storage_path' => (string) Str::uuid(), 'original_name' => 'private.pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 7, 'sha256' => str_repeat('b', 64), 'scan_status' => 'pending', 'access_level' => 'private']);
        DB::table('files')->where('id', $file)->update(['storage_path' => "{$file}.pdf"]);
        Storage::disk('candidate_evidence')->put("{$file}.pdf", 'private');
        DB::table('file_links')->insert(['file_id' => $file, 'application_id' => $app, 'purpose' => 'evidence']);

        return [$app, $version, $file];
    }
}
