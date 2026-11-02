<?php

namespace Tests\Feature\Candidate;

use App\Auth\CandidateRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * The «مترشح» classification: assigned by the backend with every new public
 * account, and reconciled for older accounts against all effective roles.
 */
class CandidateClassificationTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->startCsrfSession();
    }

    public function test_the_role_is_provisioned_by_the_migrations(): void
    {
        $role = DB::table('roles')->where('code', CandidateRole::CODE)->sole();

        $this->assertSame(['مترشح', 'platform'], [$role->name_ar, $role->scope]);
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count(), 'the classification carries no permissions');
    }

    public function test_an_account_and_its_classification_are_created_together_or_not_at_all(): void
    {
        // Without the provisioned role the grant fails, and so does the account.
        DB::table('roles')->where('code', CandidateRole::CODE)->delete();
        $this->withoutExceptionHandling();

        try {
            $this->browser('POST', '/api/auth/register', [
                'name' => 'Name', 'email' => 'new@example.org', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            ]);
            $this->fail('Registration should fail without the candidate role.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('role is missing', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::table('user_roles')->count());
    }

    public function test_a_candidate_signs_in_to_the_dashboard_and_others_to_the_account_page(): void
    {
        User::factory()->candidate()->create(['email' => 'candidate@example.org']);
        $this->userWithPlatformRole('staff', 'staff@example.org');

        $this->browser('POST', '/api/auth/login', ['email' => 'candidate@example.org', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', '/dashboard')
            ->assertJsonPath('data.is_candidate', true);

        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => 'staff@example.org', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', '/account')
            ->assertJsonPath('data.is_candidate', false);
    }

    public function test_a_signed_verification_link_stays_the_destination_after_sign_in(): void
    {
        User::factory()->candidate()->unverified()->create(['email' => 'candidate@example.org']);

        // Opening the emailed link while signed out stores it as the intended URL.
        $this->navigate('/email/verify/1/abc', ['expires' => '1', 'signature' => 'x'])->assertRedirect('/login');

        $this->browser('POST', '/api/auth/login', ['email' => 'candidate@example.org', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', '/email/verify/1/abc?expires=1&signature=x');
    }

    public function test_the_legacy_provisioning_entry_point_uses_current_roles_and_requires_explicit_apply(): void
    {
        $ordinary = User::factory()->create(['email' => 'ordinary@example.org']);
        $alreadyCandidate = User::factory()->candidate()->create(['email' => 'candidate@example.org']);
        $disabled = User::factory()->disabled()->create(['email' => 'disabled@example.org']);
        $administrator = $this->userWithPlatformRole('award_administrator', 'administrator@example.org');
        $revoked = $this->userWithPlatformRole('award_staff', 'former.staff@example.org', revoked: true);
        $judge = User::factory()->create(['email' => 'judge@example.org']);
        $coordinator = User::factory()->create(['email' => 'coordinator@example.org']);
        $this->makeCommitteeMemberAndCycleRole($judge, $coordinator);
        $grantsBefore = DB::table('user_roles')->orderBy('id')->get()->toArray();

        $this->artisan('candidates:provision', ['--dry-run' => true])
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();
        $this->assertEquals($grantsBefore, DB::table('user_roles')->orderBy('id')->get()->toArray());

        $this->artisan('candidates:provision')->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertEquals($grantsBefore, DB::table('user_roles')->orderBy('id')->get()->toArray());
        $this->artisan('candidates:provision', ['--apply' => true])->assertSuccessful();

        $candidates = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.code', CandidateRole::CODE)
            ->orderBy('user_roles.user_id')
            ->pluck('user_roles.user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->assertSame([$ordinary->id, $alreadyCandidate->id, $disabled->id, $revoked->id], $candidates);
        $this->assertFalse($disabled->fresh()->isActive());
        foreach ([$administrator, $judge, $coordinator] as $skipped) {
            $this->assertFalse(app(CandidateRole::class)->isHeldBy($skipped), $skipped->email);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user_role.granted')->where('subject_id', $ordinary->id)
            ->where('after_data', 'like', '%candidate_role_policy%')->count());

        // Idempotent: a second run finds nothing to do.
        $audits = DB::table('audit_logs')->count();
        $this->artisan('candidates:provision', ['--apply' => true])->assertSuccessful();
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->assertSame(4, DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('roles.code', CandidateRole::CODE)->count());
    }

    private function userWithPlatformRole(string $code, string $email, bool $revoked = false): User
    {
        $user = User::factory()->create(['email' => $email]);
        $roleId = DB::table('roles')->insertGetId(['code' => $code, 'name_ar' => $code, 'scope' => 'platform', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert([
            'user_id' => $user->id, 'role_id' => $roleId, 'assigned_at' => now()->subDay(),
            'revoked_at' => $revoked ? now() : null, 'revoked_by' => $revoked ? $user->id : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeCommitteeMemberAndCycleRole(User $judge, User $coordinator): void
    {
        $cycleId = DB::table('award_cycles')->insertGetId([
            'code' => 'cycle-2026', 'name_ar' => 'الدورة الأولى', 'award_name_ar' => 'الجائزة', 'year' => 2026,
            'timezone' => 'Asia/Damascus', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $committeeId = DB::table('committees')->insertGetId([
            'cycle_id' => $cycleId, 'type' => 'integrity', 'name_ar' => 'لجنة النزاهة', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('committee_members')->insert([
            'committee_id' => $committeeId, 'user_id' => $judge->id, 'position' => 'member', 'joined_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cycle_user_roles')->insert([
            'cycle_id' => $cycleId, 'user_id' => $judge->id, 'role_id' => DB::table('roles')->where('code', 'judge')->value('id'), 'starts_at' => now(),
        ]);
        $cycleRole = DB::table('roles')->insertGetId(['code' => 'cycle_coordinator', 'name_ar' => 'منسق', 'scope' => 'cycle', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cycle_user_roles')->insert([
            'cycle_id' => $cycleId, 'user_id' => $coordinator->id, 'role_id' => $cycleRole, 'starts_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
