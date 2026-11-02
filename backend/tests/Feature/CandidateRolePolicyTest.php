<?php

namespace Tests\Feature;

use App\Admin\AdminAccess;
use App\Admin\RegisteredUsers;
use App\Auth\CandidateRole;
use App\Auth\RoleExclusivity;
use App\Candidates\AccountContext;
use App\Models\Application;
use App\Models\User;
use App\Reports\PdfWriter;
use App\Reports\TableReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CandidateRolePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_roleless_unverified_account_gets_a_real_default_grant_on_its_first_request(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', true)->assertJsonPath('data.email_verified', false)->assertJsonPath('data.roles.0.code', 'candidate');
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => app(CandidateRole::class)->roleId(), 'revoked_at' => null]);
        $this->assertDatabaseCount('candidate_profiles', 0);
        $events = DB::table('audit_logs')->count();
        $this->getJson('/api/user')->assertOk();
        $this->assertSame($events, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('user_roles', 1);
    }

    public function test_admin_grant_removes_candidate_and_direct_candidate_routes_are_refused(): void
    {
        $user = User::factory()->candidate()->create();
        $grant = DB::table('user_roles')->where('user_id', $user->id)->sole();
        app(AdminAccess::class)->grantAdmin($user);
        $this->assertFalse(app(CandidateRole::class)->isHeldBy($user));
        $this->assertDatabaseMissing('user_roles', ['id' => $grant->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('user_roles', ['id' => $grant->id, 'revocation_source' => RoleExclusivity::SOURCE]);
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', false)->assertJsonPath('data.roles.0.code', 'award_admin');
        foreach (['overview', 'basic-profile', 'nomination-details', 'documentation', 'review'] as $route) {
            $this->getJson('/api/candidate/'.$route)->assertForbidden()->assertJsonPath('code', RoleExclusivity::UNAVAILABLE);
        }
        $this->postJson('/api/candidate/submission', [])->assertForbidden();
        $events = DB::table('audit_logs')->count();
        $this->assertFalse(app(AdminAccess::class)->grantAdmin($user));
        $this->assertSame($events, DB::table('audit_logs')->count());
    }

    /** @return array<string, array{string}> */
    public static function platformRoles(): array
    {
        return ['administration' => ['award_admin'], 'custom role' => ['custom_platform']];
    }

    #[DataProvider('platformRoles')]
    public function test_platform_windows_take_effect_on_the_first_request_without_a_scheduler(string $code): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(RoleExclusivity::class);
        if ($code !== 'award_admin') {
            $this->customRole($code, 'platform');
        }
        $user = User::factory()->candidate()->create();
        $start = now()->addHour();
        $end = now()->addHours(2);
        $policy->grantPlatform($user, $code, 'test', startsAt: $start, endsAt: $end);
        $events = DB::table('audit_logs')->count();
        $this->assertFalse($policy->grantPlatform($user, $code, 'test', startsAt: $start, endsAt: $end));
        $this->assertSame($events, DB::table('audit_logs')->count(), 'repeating a future grant preserves its original assignment and audit history');
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', true)->assertJsonCount(1, 'data.roles');
        $this->travelTo($start);
        $active = $this->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', false)->assertJsonPath('data.roles.0.code', $code);
        $this->assertSame($code === 'award_admin', count($active->json('data.admin_permissions')) > 0);
        $this->getJson('/api/candidate/overview')->assertForbidden();
        $this->assertFalse($policy->isCandidate($user));
        $this->travelTo($end);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', true)->assertJsonPath('data.admin_permissions', [])->assertJsonPath('data.roles.0.code', 'candidate');
        $this->assertTrue($policy->isCandidate($user));
        $this->assertSame(2, DB::table('user_roles')->where('user_id', $user->id)->where('role_id', app(CandidateRole::class)->roleId())->count());
    }

    public function test_an_account_created_for_other_roles_returns_to_candidate_without_candidate_history(): void
    {
        $policy = app(RoleExclusivity::class);
        $operator = User::factory()->create();
        $user = User::factory()->create();
        $cycleA = $this->cycle('cycle-a');
        $cycleB = $this->cycle('cycle-b');
        $this->customRole('custom_cycle', 'cycle');
        $policy->grantCycle($user, 'judge', $cycleA, $operator);
        $policy->grantCycle($user, 'custom_cycle', $cycleB, $operator);
        $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id, 'role_id' => app(CandidateRole::class)->roleId()]);
        $policy->revokeCycle($user, 'judge', $cycleA, $operator, 'Assignment ended');
        $this->assertFalse($policy->isCandidate($user));
        $this->assertTrue($policy->hasOtherRoles($user->id));
        $policy->revokeCycle($user, 'custom_cycle', $cycleB, $operator, 'Assignment ended');
        $this->assertTrue($policy->isCandidate($user));
        $count = DB::table('audit_logs')->count();
        $policy->revokeCycle($user, 'custom_cycle', $cycleB, $operator, 'Assignment ended');
        $this->assertSame($count, DB::table('audit_logs')->count());
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $user->id)->count());
    }

    public function test_multiple_other_roles_remain_together_and_only_the_last_end_restores_candidate(): void
    {
        $policy = app(RoleExclusivity::class);
        $this->customRole('custom_platform', 'platform');
        $user = User::factory()->candidate()->create();
        app(AdminAccess::class)->grantAdmin($user);
        $policy->grantPlatform($user, 'custom_platform', 'test');
        $this->assertCount(2, $policy->currentRoles($user));
        $policy->revokePlatform($user, 'award_admin');
        $this->assertFalse($policy->isCandidate($user));
        $policy->revokePlatform($user, 'custom_platform');
        $this->assertTrue($policy->isCandidate($user));
    }

    public function test_future_expired_and_revoked_cycle_grants_do_not_suppress_the_default(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(RoleExclusivity::class);
        $operator = User::factory()->create();
        $user = User::factory()->candidate()->create();
        $this->customRole('custom_cycle', 'cycle');
        $cycle = $this->cycle('temporal-cycle');
        $policy->grantCycle($user, 'custom_cycle', $cycle, $operator, now()->addHour(), now()->addHours(2));
        $this->assertTrue($policy->isCandidate($user));
        $policy->revokeCycle($user, 'custom_cycle', $cycle, $operator, 'Cancelled before starting');
        $this->travel(3)->hours();
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', true);
        $policy->grantCycle($user, 'judge', $cycle, $operator, now()->subHours(2), now()->subHour());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', true)->assertJsonPath('data.is_judge', false);
    }

    public function test_preview_apply_and_repeated_apply_preserve_deleted_archival_disabled_and_correct_accounts(): void
    {
        $roleless = User::factory()->create();
        $disabled = User::factory()->disabled()->create();
        $correct = User::factory()->candidate()->create();
        $admin = User::factory()->create();
        app(AdminAccess::class)->grantAdmin($admin);
        DB::table('user_roles')->insert(['user_id' => $admin->id, 'role_id' => app(CandidateRole::class)->roleId(), 'assigned_at' => now()]);
        $deleted = User::factory()->disabled()->create();
        $deleted->forceFill(['deleted_at' => now()])->save();
        $archive = User::factory()->disabled()->create(['email' => 'archive-fixture@deleted.invalid']);
        $snapshot = fn (): array => collect(['users', 'user_roles', 'cycle_user_roles', 'audit_logs'])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all();
        $before = $snapshot();
        $this->artisan('roles:reconcile-candidates', ['--dry-run' => true])->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertEquals($before, $snapshot());
        $this->artisan('roles:reconcile-candidates', ['--apply' => true])->assertSuccessful();
        foreach ([$roleless, $disabled, $correct] as $user) {
            $this->assertTrue(app(CandidateRole::class)->isHeldBy($user));
        }
        $this->assertFalse(app(CandidateRole::class)->isHeldBy($admin));
        $this->assertFalse($disabled->fresh()->isActive());
        foreach ([$deleted, $archive] as $user) {
            $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id]);
        }
        $after = $snapshot();
        $this->artisan('roles:reconcile-candidates', ['--apply' => true])->assertSuccessful();
        $this->assertEquals($after, $snapshot());
        $this->actingAs($disabled)->getJson('/api/user')->assertForbidden();
    }

    public function test_user_list_filters_and_export_use_all_cycles_and_include_custom_roles(): void
    {
        $policy = app(RoleExclusivity::class);
        $admin = User::factory()->create();
        app(AdminAccess::class)->grantAdmin($admin);
        $a = $this->cycle('role-cycle-a');
        $b = $this->cycle('role-cycle-b');
        $judge = User::factory()->candidate()->create(['name' => 'Cross-cycle Judge']);
        $custom = User::factory()->candidate()->create(['name' => 'Custom-role User']);
        $this->customRole('custom_cycle', 'cycle');
        $policy->grantCycle($judge, 'judge', $a, $admin);
        $policy->grantCycle($custom, 'custom_cycle', $b, $admin);
        $this->actingAs($admin);
        foreach ([$a, $b] as $displayedCycle) {
            $this->getJson('/api/admin/users?role=judge&cycle='.$displayedCycle)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $judge->id)->assertJsonPath('data.0.roles.platform', [])->assertJsonPath('data.0.roles.cycle.0.code', 'judge');
        }
        $this->getJson('/api/admin/users?role=custom_cycle&cycle='.$a)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $custom->id);
        $export = app(RegisteredUsers::class)->export(['role' => 'judge'], $b, false);
        $this->assertSame([$judge->id], array_column($export['data'], 'id'));
        $this->assertSame('judge', $export['data'][0]['roles']['cycle'][0]['code']);
        $this->actingAs($custom)->getJson('/api/user')->assertOk()->assertJsonPath('data.is_candidate', false)->assertJsonPath('data.roles.0.code', 'custom_cycle');
        $this->getJson('/api/candidate/overview')->assertForbidden();
    }

    public function test_role_changes_preserve_submitted_versions_files_and_the_read_only_submission(): void
    {
        Storage::fake('candidate_evidence');
        $user = User::factory()->candidate()->create();
        $cycle = $this->cycle('history-cycle');
        $domain = DB::table('award_domains')->insertGetId(['code' => 'history-domain', 'name_ar' => 'History', 'is_active' => true]);
        $domain = DB::table('cycle_domains')->insertGetId(['cycle_id' => $cycle, 'domain_id' => $domain, 'name_ar' => 'History']);
        $applicant = DB::table('applicants')->insertGetId(['type' => 'individual', 'name_ar' => 'Test applicant', 'representative_user_id' => $user->id, 'status' => 'active']);
        $application = DB::table('applications')->insertGetId(['cycle_id' => $cycle, 'cycle_domain_id' => $domain, 'applicant_id' => $applicant, 'created_by' => $user->id, 'title' => 'Immutable work', 'status' => 'draft']);
        $version = DB::table('application_versions')->insertGetId(['application_id' => $application, 'version_number' => 1, 'submission_type' => 'initial', 'submitted_by' => $user->id, 'submitted_at' => now(), 'data_snapshot' => '{"immutable":true}', 'form_snapshot' => '{}', 'content_hash' => str_repeat('a', 64)]);
        DB::table('applications')->where('id', $application)->update(['status' => 'submitted', 'current_version_id' => $version, 'first_submitted_at' => now(), 'review_status' => 'pending']);
        $file = DB::table('files')->insertGetId(['uploaded_by' => $user->id, 'storage_disk' => 'candidate_evidence', 'storage_path' => 'role-history.pdf', 'original_name' => 'evidence.pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 7, 'sha256' => str_repeat('b', 64), 'scan_status' => 'pending', 'access_level' => 'private']);
        DB::table('file_links')->insert(['file_id' => $file, 'application_id' => $application, 'purpose' => 'evidence']);
        Storage::disk('candidate_evidence')->put('role-history.pdf', 'private');
        $snapshot = fn (): array => collect(['applications', 'application_versions', 'files', 'file_links'])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all();
        $before = $snapshot();
        app(AdminAccess::class)->grantAdmin($user);
        $this->actingAs($user)->postJson('/api/candidate/submission', ['account_context' => AccountContext::for($user)])->assertForbidden();
        app(RoleExclusivity::class)->revokePlatform($user, 'award_admin');
        $this->assertTrue(app(CandidateRole::class)->isHeldBy($user));
        $this->assertFalse(Application::findOrFail($application)->isEditable());
        $this->assertEquals($before, $snapshot());
        Storage::disk('candidate_evidence')->assertExists('role-history.pdf');
    }

    public function test_real_excel_and_pdf_reports_agree_with_current_role_filters_and_authorization(): void
    {
        $admin = User::factory()->create();
        app(AdminAccess::class)->grantAdmin($admin);
        $user = User::factory()->candidate()->create(['name' => 'Export fixture']);
        $cycle = $this->cycle('export-cycle');
        $otherCycle = $this->cycle('displayed-cycle');
        $role = $this->customRole('report_coordinator', 'cycle');
        DB::table('roles')->where('id', $role)->update(['name_ar' => 'منسق تجريبي']);
        app(RoleExclusivity::class)->grantCycle($user, 'report_coordinator', $cycle, $admin);
        $this->actingAs($admin);
        $query = 'role=report_coordinator&cycle='.$otherCycle;
        $list = $this->getJson('/api/admin/users?'.$query)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $expected = $list['roles']['cycle'][0]['name'].' ('.$list['roles']['cycle'][0]['cycle']['name'].' — 2026)';
        $headers = ['X-Candidate-Context' => AccountContext::for($admin)];
        $response = $this->getJson('/api/admin/exports/users?format=xlsx&'.$query, $headers)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        try {
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
            $exported = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === $user->name);
            $this->assertSame($expected, $exported[3]);
            $this->assertStringNotContainsString('مترشح', $exported[3]);
        } finally {
            $reader->close();
            @unlink($path);
        }
        $capture = new class(app(PdfWriter::class))
        {
            public ?TableReport $report = null;

            public function __construct(private PdfWriter $writer) {}

            public function table(TableReport $report): string
            {
                $this->report = $report;

                return $this->writer->table($report);
            }
        };
        $this->app->instance(PdfWriter::class, $capture);
        $pdf = $this->getJson('/api/admin/exports/users?format=pdf&'.$query, $headers)->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame($expected, $capture->report->rows[0]['roles']);
        $this->assertCount(1, $capture->report->rows);
        $this->actingAs($user)->getJson('/api/candidate/overview')->assertForbidden()->assertJsonPath('code', RoleExclusivity::UNAVAILABLE);
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_cycle_boundaries_renewals_and_timezone_offsets_use_the_same_effective_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(RoleExclusivity::class);
        $actor = User::factory()->create();
        $user = User::factory()->candidate()->create();
        $cycle = $this->cycle('window-cycle');
        $start = now()->addHour()->setTimezone('Asia/Damascus');
        $end = $start->copy()->addHour();
        $policy->grantCycle($user, 'judge', $cycle, $actor, $start, $end);
        $this->actingAs($user)->getJson('/api/user')->assertJsonPath('data.is_candidate', true)->assertJsonPath('data.is_judge', false);
        $this->travelTo($start->copy()->utc());
        $this->getJson('/api/user')->assertJsonPath('data.is_candidate', false)->assertJsonPath('data.is_judge', true);
        $this->travelTo($end->copy()->utc());
        $this->getJson('/api/user')->assertJsonPath('data.is_candidate', true)->assertJsonPath('data.is_judge', false);
        $policy->grantCycle($user, 'judge', $cycle, $actor);
        $this->getJson('/api/user')->assertJsonPath('data.is_candidate', false)->assertJsonPath('data.is_judge', true);
        $this->assertSame(2, DB::table('cycle_user_roles')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('cycle_user_roles')->where('user_id', $user->id)->whereNull('revoked_at')->count());
        $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now()])->save();
        $policy->revokeCycle($user, 'judge', $cycle, $actor, 'Ended while disabled');
        $this->assertTrue($policy->isCandidate($user));
        $this->assertFalse($user->fresh()->isActive());
    }

    private function customRole(string $code, string $scope): int
    {
        return DB::table('roles')->insertGetId(['code' => $code, 'name_ar' => $code, 'scope' => $scope]);
    }

    private function cycle(string $code): int
    {
        return DB::table('award_cycles')->insertGetId(['code' => $code, 'name_ar' => $code, 'award_name_ar' => 'Test award', 'year' => 2026, 'timezone' => 'Asia/Damascus', 'status' => 'draft']);
    }
}
