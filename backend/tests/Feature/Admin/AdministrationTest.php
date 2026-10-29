<?php

namespace Tests\Feature\Admin;

use App\Admin\AdminAccess;
use App\Admin\AuditTrail;
use App\Admin\SubmittedApplications;
use App\Auth\CandidateRole;
use App\Candidates\AccountContext;
use App\Judging\Assignments;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use App\Reports\ApplicationCard;
use App\Reports\PdfWriter;
use App\Reports\TableReport;
use App\Reports\XlsxWriter;
use App\Support\CanonicalJson;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Tests\ActsAsSpaBrowser;
use Tests\Feature\Candidate\InteractsWithNominationStage;
use Tests\TestCase;

/**
 * The first administration phase: access (granted only by the operator
 * command; candidates and judges refused), «ملفات الترشح» (submitted
 * participations only, read-only, files bound to the submitted version) and
 * «لجان الحكام» (main-domain committees, judge accounts, 3-or-5 approval),
 * and the conflict-of-interest rules between appointments and submissions.
 */
class AdministrationTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage, RefreshDatabase;

    private const NATIONAL_ID = '04019876543';

    private User $admin;

    private int $cycleId;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('candidate_evidence');
        Notification::fake();
        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'operator@example.org']);
        $this->candidate = User::factory()->candidate()->create(['email' => 'layla@example.org']);
        $this->admin = User::factory()->create(['email' => 'admin@example.org', 'name' => 'مدير الجائزة']);
        $this->configureCycle();
        $this->cycleId = (int) DB::table('award_cycles')->where('code', 'cycle-2026')->value('id');

        // The built app's page (as in SpaRoutingTest).
        $this->publicPath = storage_path('framework/testing/admin-public-'.uniqid());
        File::ensureDirectoryExists($this->publicPath);
        File::put($this->publicPath.'/index.html', '<!doctype html><html lang="ar" dir="rtl"><div id="root"></div></html>');
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_administration_access_is_explicit_and_refused_to_candidates_and_judges(): void
    {
        // The command: an explicit existing email only; dry run; idempotent; never a disabled account.
        $this->artisan('admin:grant-access')->assertExitCode(2);
        $this->artisan('admin:grant-access', ['--email' => 'nobody@example.org'])->assertFailed();
        $this->artisan('admin:grant-access', ['--email' => 'admin@example.org', '--dry-run' => true])->assertSuccessful();
        $this->assertFalse(app(AdminAccess::class)->isAdmin($this->admin));
        $this->artisan('admin:grant-access', ['--email' => ' Admin@Example.org '])->assertSuccessful();
        $this->artisan('admin:grant-access', ['--email' => 'admin@example.org'])->assertSuccessful()->expectsOutputToContain('already');
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $this->admin->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user_role.granted')->where('subject_id', $this->admin->id)->count());
        $disabled = User::factory()->create(['email' => 'off@example.org', 'status' => 'disabled', 'disabled_at' => now()]);
        $this->artisan('admin:grant-access', ['--email' => $disabled->email])->assertFailed();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $disabled->id)->count());

        // Signed out: pages go to sign-in, the API answers 401.
        $this->navigate('/admin/committees')->assertRedirect('/login?redirect=%2Fadmin%2Fcommittees');
        $this->browser('GET', '/api/admin/context')->assertUnauthorized();

        // A candidate: refused everywhere, the page included.
        $this->signIn();
        $this->read('/api/user')->assertJsonPath('data.admin_permissions', [])->assertJsonPath('data.is_judge', false);
        $refused = $this->navigate('/admin')->assertForbidden();
        $this->assertStringContainsString('<div id="root">', (string) $refused->getContent(), 'the page itself, so the browser shows the refusal');
        foreach (['/api/admin/context', '/api/admin/applications', '/api/admin/applications/1', '/api/admin/applications/1/files/1', "/api/admin/cycles/{$this->cycleId}/committees"] as $uri) {
            $this->read($uri)->assertForbidden()->assertJsonPath('code', 'not_admin');
        }
        $this->adminPost('/judges', ['domain' => 'creativity', 'email' => 'x@example.org', 'name' => 'س', 'existing' => false])->assertForbidden();
        $this->assertSame(0, DB::table('committee_members')->count());

        // The administrator: both permissions, /admin after sign-in, real pages only.
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $this->admin->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', '/admin');
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $this->admin->email, 'password' => 'password', 'redirect' => '/admin/committees'])->assertJsonPath('redirect', '/admin/committees');
        $this->signedIn = $this->admin;
        $this->read('/api/user')->assertJsonPath('data.admin_permissions', [
            AdminAccess::REVIEW_APPLICATIONS, AdminAccess::VIEW_APPLICATIONS, AdminAccess::VIEW_AUDIT, AdminAccess::MANAGE_COMMITTEES,
            AdminAccess::VIEW_SYSTEM_LOGS, AdminAccess::EXPORT_REPORTS, AdminAccess::VIEW_USERS,
        ]);
        $this->navigate('/admin/applications')->assertOk();
        $this->navigate('/admin/applications/12')->assertOk();
        $this->navigate('/admin/settings')->assertNotFound();
        $this->navigate('/admin/applications/abc')->assertNotFound();
        $this->read('/api/admin/context')->assertOk()->assertJsonPath('data.default_cycle_id', $this->cycleId)
            ->assertJsonPath('data.cycles.0.domains.2.code', 'creativity');
        // Writes need the page's account snapshot.
        $this->browser('POST', "/api/admin/cycles/{$this->cycleId}/committees", ['domain' => 'creativity', 'name' => 'لجنة', 'account_context' => 'stale'])
            ->assertConflict()->assertJsonPath('code', 'account_mismatch');

        // A judge (an existing candidate appointed here): the judge portal, never the candidate dashboard or administration.
        $this->createCommittee('creativity')->assertCreated();
        $this->appoint('creativity', $this->candidate->email, existing: true, extra: ['role' => 'award_admin', 'actor_id' => $this->operator->id])->assertCreated();
        $this->assertSame((int) $this->admin->id, (int) DB::table('audit_logs')->where('action', 'committee_member.added')->value('actor_user_id'));
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $this->candidate->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', '/judge');
        $this->signedIn = $this->candidate;
        $this->navigate('/judge')->assertOk();
        $this->navigate('/judge/projects/1')->assertOk();
        $this->read('/api/user')->assertJsonPath('data.is_judge', true)->assertJsonPath('data.is_candidate', false)->assertJsonPath('data.admin_permissions', []);
        $this->read('/api/candidate/overview')->assertForbidden()->assertJsonPath('code', 'not_candidate');
        $this->read('/api/admin/applications')->assertForbidden();
        $this->navigate('/admin')->assertForbidden();
    }

    public function test_a_judge_who_registers_as_a_participant_loses_the_judge_role(): void
    {
        $legacy = User::factory()->candidate()->create(['email' => 'legacy@example.org']);
        DB::table('cycle_user_roles')->insert([
            'cycle_id' => $this->cycleId, 'user_id' => $legacy->id, 'role_id' => app(AdminAccess::class)->roleId('judge'),
            'assigned_by' => $this->admin->id, 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_29_900004_separate_judge_and_candidate_roles.php');
        $migration->up();
        $this->assertFalse(app(CandidateRole::class)->isHeldBy($legacy));
        $this->assertTrue(app(AdminAccess::class)->isJudge((int) $legacy->id));
        $migration->down();
        $this->assertTrue(app(CandidateRole::class)->isHeldBy($legacy));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'user_role.separated')->count());

        $this->signInAdmin();
        $this->createCommittee('creativity')->assertCreated();
        $this->appoint('creativity', $this->candidate->email, existing: true)->assertCreated();
        $this->signIn($this->candidate->email);
        $this->browser('POST', '/api/account/participate', ['account_context' => 'stale'])->assertConflict()->assertJsonPath('code', 'account_mismatch');
        $this->assertTrue(app(AdminAccess::class)->isJudge((int) $this->candidate->id), 'a stale page changes nothing');
        $this->browser('POST', '/api/account/participate', ['account_context' => AccountContext::for($this->candidate)])->assertOk()
            ->assertJsonPath('data.is_judge', false)
            ->assertJsonPath('data.is_candidate', true)
            ->assertJsonPath('redirect', '/dashboard');
        $this->assertNotNull(DB::table('committee_members')->where('user_id', $this->candidate->id)->value('left_at'));
        $this->assertNotNull(DB::table('cycle_user_roles')->where('user_id', $this->candidate->id)->value('revoked_at'));
        $this->read('/api/judge/projects')->assertForbidden()->assertJsonPath('code', 'not_judge');

        $only = $this->person('only.candidate@example.org');
        $this->signIn($only->email);
        $this->browser('POST', '/api/account/participate', ['account_context' => AccountContext::for($only)])->assertConflict()->assertJsonPath('code', 'not_judge');
        $this->assertTrue(app(CandidateRole::class)->isHeldBy($only));
    }

    public function test_submitted_applications_are_listed_filtered_searched_sorted_and_paged(): void
    {
        $first = $this->submitApplication($this->candidate, ['title' => 'مبادرة ذاكرة الحيّ']);
        $second = $this->submitApplication($this->person('sami@example.org'), ['title' => 'ورشة الخط العربي']);
        $institution = $this->person('org@example.org');
        $third = $this->submitApplication($institution, ['domain' => 'heritage', 'subcategory' => 'tangible_heritage', 'title' => 'ترميم خان قديم']);
        // As Stage 1 stores an institution (applicants.type = organization).
        DB::table('applicants')->where('representative_user_id', $institution->id)->update(['type' => 'organization', 'name_ar' => 'جمعية العاديات']);
        // A draft (Stage 2 confirmed, never submitted) never appears.
        $draft = $this->person('draft@example.org');
        $this->completeStage1($draft);
        $this->signIn($draft->email);
        $this->confirm(['title' => 'مسودة'] + $this->payload(), $this->journeyKey())->assertCreated();
        // A later workflow status keeps it listed.
        DB::table('applications')->where('id', $second['id'])->update(['status' => 'eligible']);

        $this->signInAdmin();
        $all = $this->read('/api/admin/applications')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertNotContains('مسودة', array_column($all->json('data'), 'title'));
        $this->assertSame([1, 1, 1], array_column($all->json('data'), 'attachment_count'));
        $this->assertSame($third['reference_number'], $all->json('data.0.reference_number'), 'newest submission first');
        $this->assertStringNotContainsString(self::NATIONAL_ID, $all->getContent());

        $titles = fn (string $query): array => array_column($this->read('/api/admin/applications?'.$query)->assertOk()->json('data'), 'title');
        $this->assertSame(['ترميم خان قديم'], $titles('domain=heritage'));
        $this->assertSame(['ترميم خان قديم'], $titles('domain=heritage&subcategory=tangible_heritage'));
        $this->assertSame([], $titles('domain=heritage&subcategory=quality'));
        $this->assertSame(['ترميم خان قديم'], $titles('applicant_type=institution'));
        $this->assertSame('institution', $this->read('/api/admin/applications?q=العاديات')->json('data.0.applicant_type'));
        $this->assertCount(2, $titles('applicant_type=individual'));
        $this->assertSame(['ورشة الخط العربي'], $titles('status=eligible'));
        $this->assertSame(['ورشة الخط العربي'], $titles('q=الخط'));
        $this->assertSame(['ترميم خان قديم'], $titles('q=العاديات'));
        $this->assertSame(['مبادرة ذاكرة الحيّ'], $titles('q='.$first['reference_number']));
        $this->assertSame([], $titles('cycle='.($this->cycleId + 99)));
        $this->assertSame(['ترميم خان قديم', 'مبادرة ذاكرة الحيّ', 'ورشة الخط العربي'], $titles('sort=title&direction=asc'));
        $paged = $this->read('/api/admin/applications?per_page=10&page=1&sort=reference&direction=asc')->assertOk();
        $this->assertSame(['page' => 1, 'per_page' => 10, 'total' => 3, 'last_page' => 1], $paged->json('meta'));
        $this->read('/api/admin/applications?page=9')->assertOk()->assertJsonPath('meta.page', 1);
        $this->read('/api/admin/applications?sort=national_id_number')->assertUnprocessable();
        $this->read('/api/admin/applications?status=draft')->assertUnprocessable();
        $this->read('/api/admin/applications?per_page=1000')->assertUnprocessable();
    }

    public function test_a_submitted_application_is_read_only_and_its_files_are_bound_to_its_version(): void
    {
        $mine = $this->submitApplication($this->candidate);
        $other = $this->submitApplication($this->person('sami@example.org'));
        $this->signInAdmin();

        $page = $this->read("/api/admin/applications/{$mine['id']}")->assertOk();
        $this->assertSame($mine['reference_number'], $page->json('data.summary.reference_number'));
        $this->assertSame('creativity', $page->json('data.summary.domain.code'));
        $this->assertSame(['confirmed_at', 'cycle', 'domain', 'subcategory', 'title', 'purpose_and_mission', 'objectives', 'impact'], array_keys($page->json('data.nomination_details')));
        $this->assertSame('pending', $page->json('data.review.status'));
        $this->assertSame(self::NATIONAL_ID, $page->json('data.basic_information.individual.national_id_number'), 'the full basic information, for administrators');
        $this->assertSame(['explanatory'], array_column($page->json('data.documentation.attachments'), 'kind'));
        $this->assertSame('pending', $page->json('data.documentation.attachments.0.scan_status'));
        $this->assertArrayNotHasKey('storage_path', $page->json('data.documentation.attachments.0'));
        $this->assertNotEmpty($page->json('data.declarations.data_accuracy_declared_at'));

        // Files of this application's submitted version only.
        $link = (int) $page->json('data.documentation.attachments.0.id');
        $download = $this->read("/api/admin/applications/{$mine['id']}/files/{$link}")->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $otherLink = (int) DB::table('file_links')->where('application_version_id', DB::table('applications')->where('id', $other['id'])->value('current_version_id'))->value('id');
        $this->read("/api/admin/applications/{$mine['id']}/files/{$otherLink}")->assertNotFound();
        $draftLink = (int) DB::table('file_links')->where('application_id', $mine['id'])->value('id');
        $this->read("/api/admin/applications/{$mine['id']}/files/{$draftLink}")->assertNotFound();

        // A draft is not a submitted application; nothing can be written.
        DB::table('applications')->where('id', $other['id'])->update(['status' => 'draft']);
        $this->read("/api/admin/applications/{$other['id']}")->assertNotFound();
        $before = DB::table('applications')->where('id', $mine['id'])->first();
        $this->browser('PUT', "/api/admin/applications/{$mine['id']}", ['title' => 'x', 'account_context' => AccountContext::for($this->admin)])->assertMethodNotAllowed();
        $this->browser('DELETE', "/api/admin/applications/{$mine['id']}/files/{$link}")->assertMethodNotAllowed();
        $this->assertEquals($before, DB::table('applications')->where('id', $mine['id'])->first());

        // The candidate's own download is unchanged, and still owner-only.
        $this->signIn();
        $docKey = (string) $this->read('/api/candidate/documentation')->json('journey_key');
        $ownerLink = (int) $this->read('/api/candidate/documentation')->json('data.attachments.0.id');
        $this->browser('GET', "/api/candidate/documentation/files/{$ownerLink}", ['account_context' => AccountContext::for($this->candidate), 'journey_key' => $docKey])->assertOk();
        $this->read("/api/admin/applications/{$mine['id']}/files/{$link}")->assertForbidden();
    }

    public function test_judges_are_appointed_by_main_domain_without_altering_accounts(): void
    {
        $this->signInAdmin();
        $this->appoint('creativity', 'new.judge@example.org')->assertConflict()->assertJsonPath('code', 'committee_missing');
        $this->createCommittee('creativity')->assertCreated()->assertJsonPath('data.2.committee.status', 'draft');
        $this->createCommittee('creativity')->assertConflict()->assertJsonPath('code', 'committee_exists');
        $this->createCommittee('volunteering')->assertUnprocessable();
        $this->assertSame(1, DB::table('committees')->count());

        // A new account: no password, unverified, no candidate role; the setup link is the reset link.
        $this->adminPost('/judges/lookup', ['email' => 'New.Judge@example.org'])->assertOk()->assertJsonPath('data.account', null);
        $created = $this->appoint('creativity', 'New.Judge@example.org', extra: ['phone' => '+963 944 123 456', 'specialty' => 'الفنون البصرية', 'subcategory' => 'quality'])
            ->assertCreated()->assertJsonPath('appointment.account_created', true)->assertJsonPath('appointment.setup_link', 'sent');
        $judge = User::query()->where('email', 'new.judge@example.org')->sole();
        $this->assertNull($judge->password);
        $this->assertNull($judge->email_verified_at);
        $this->assertSame('+963944123456', $judge->phone);
        $this->assertFalse(app(CandidateRole::class)->isHeldBy($judge));
        $this->assertTrue(app(AdminAccess::class)->isJudge((int) $judge->id, $this->cycleId));
        Notification::assertSentTo($judge, ResetPassword::class);
        $member = $created->json('data.2.committee.members.0');
        $this->assertSame(['الفنون البصرية', 'الإبداع', true], [$member['specialty'], $member['domain'], $member['account']['setup_pending']]);
        foreach (['user.created', 'cycle_user_role.granted', 'committee_member.added', 'committee.created'] as $action) {
            $this->assertSame((int) $this->admin->id, (int) DB::table('audit_logs')->where('action', $action)->value('actor_user_id'), $action);
        }
        $this->assertStringNotContainsString('password', (string) DB::table('audit_logs')->where('action', 'user.created')->value('after_data'));

        // An existing account: identified first, appointed only when confirmed, never altered.
        $this->candidate->forceFill(['phone' => '+963933000111'])->save();
        DB::table('oauth_identities')->insert(['user_id' => $this->candidate->id, 'provider' => 'google', 'provider_subject' => 'g-1', 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('users')->where('id', $this->candidate->id)->first();
        $this->adminPost('/judges/lookup', ['email' => $this->candidate->email])->assertOk()->assertJsonPath('data.account.email', $this->candidate->email)
            ->assertJsonPath('data.account.setup_pending', false);
        $this->appoint('creativity', $this->candidate->email, extra: ['name' => 'اسم آخر'])->assertConflict()->assertJsonPath('code', 'account_exists')
            ->assertJsonPath('account.name', $this->candidate->name);
        $this->appoint('creativity', $this->candidate->email, existing: true, extra: ['name' => 'اسم آخر', 'phone' => '+963944999999'])->assertCreated()
            ->assertJsonPath('appointment.account_created', false)->assertJsonPath('appointment.setup_link', null);
        $this->assertEquals($before, DB::table('users')->where('id', $this->candidate->id)->first(), 'password, verification, name and phone untouched');
        $this->assertSame(1, DB::table('oauth_identities')->where('user_id', $this->candidate->id)->count());
        $this->assertFalse(app(CandidateRole::class)->isHeldBy($this->candidate), 'appointing a judge revokes the candidate classification');
        Notification::assertNotSentTo($this->candidate, ResetPassword::class);

        // One current main-domain membership per cycle; a disabled account is refused, not reactivated.
        $this->createCommittee('heritage')->assertCreated();
        $this->appoint('heritage', $this->candidate->email, existing: true)->assertConflict()->assertJsonPath('code', 'already_judge');
        $this->appoint('creativity', $judge->email, existing: true)->assertConflict()->assertJsonPath('code', 'already_judge');
        $off = User::factory()->create(['email' => 'off@example.org', 'status' => 'disabled', 'disabled_at' => now()]);
        $this->appoint('heritage', $off->email, existing: true)->assertConflict()->assertJsonPath('code', 'account_disabled');
        $this->assertSame('disabled', $off->fresh()->status);
        $this->appoint('heritage', 'ghost@example.org', existing: true)->assertConflict()->assertJsonPath('code', 'account_missing');
        $this->assertSame(2, DB::table('committee_members')->count());
        $this->assertSame(2, DB::table('cycle_user_roles')->whereNull('revoked_at')->count());
        // The last guard against two simultaneous appointments of one person: one current judge grant per cycle.
        $guarded = false;
        try {
            DB::table('cycle_user_roles')->insert(['cycle_id' => $this->cycleId, 'user_id' => $judge->id, 'role_id' => app(AdminAccess::class)->roleId('judge'), 'starts_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $guarded = true;
        }
        $this->assertTrue($guarded, 'uq_cycle_user_roles_current refuses a second current judge grant');

        // Another cycle: its route never reaches this committee's members, and serving there is separate.
        $this->configureCycle('cycle-2027');
        $otherCycle = (int) DB::table('award_cycles')->where('code', 'cycle-2027')->value('id');
        $memberId = (int) DB::table('committee_members')->where('user_id', $judge->id)->value('id');
        $this->adminPost("/committees/creativity/members/{$memberId}/end", [], $otherCycle)->assertNotFound();
        $this->assertNull(DB::table('committee_members')->where('id', $memberId)->value('left_at'));
        $this->adminPost('/committees', ['domain' => 'creativity', 'name' => 'لجنة 2027'], $otherCycle)->assertCreated();
        $this->appoint('creativity', $judge->email, existing: true, cycle: $otherCycle)->assertCreated();
    }

    public function test_an_existing_account_is_appointed_only_as_confirmed(): void
    {
        $this->signInAdmin();
        $this->createCommittee('heritage')->assertCreated();
        $other = $this->person('sami@example.org');
        $other->forceFill(['phone' => '+963933000222'])->save();
        $before = DB::table('users')->whereIn('id', [$this->candidate->id, $other->id])->orderBy('id')->get();

        // A slow lookup of A is shown while the field now holds B: A's confirmation never appoints B.
        $refOfA = (string) $this->adminPost('/judges/lookup', ['email' => $this->candidate->email])->json('data.account.account_ref');
        $this->assertSame(64, strlen($refOfA));
        $this->appoint('heritage', $other->email, existing: true, extra: ['account_ref' => $refOfA])->assertConflict()->assertJsonPath('code', 'account_changed');
        // No confirmation at all: nothing is resolved by email alone.
        $this->appoint('heritage', $other->email, existing: true, extra: ['account_ref' => null])->assertUnprocessable()->assertJsonValidationErrors(['account_ref']);

        // A's email changed after the lookup: the old binding no longer matches, checked under A's lock.
        DB::table('users')->where('id', $this->candidate->id)->update(['email' => 'layla.new@example.org']);
        $this->appoint('heritage', 'layla.new@example.org', existing: true, extra: ['account_ref' => $refOfA])->assertConflict()->assertJsonPath('code', 'account_changed');
        $this->appoint('heritage', 'layla@example.org', existing: true, extra: ['account_ref' => $refOfA])->assertConflict()->assertJsonPath('code', 'account_missing');
        $this->assertSame(0, DB::table('committee_members')->count());
        $this->assertSame(0, DB::table('cycle_user_roles')->count());
        DB::table('users')->where('id', $this->candidate->id)->update(['email' => 'layla@example.org']);
        $this->assertEquals($before, DB::table('users')->whereIn('id', [$this->candidate->id, $other->id])->orderBy('id')->get(), 'no account was changed');

        // A fresh lookup, confirmed as shown, appoints exactly that account.
        $this->appoint('heritage', $other->email, existing: true)->assertCreated();
        $this->assertSame([(int) $other->id], DB::table('committee_members')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        Notification::assertNotSentTo($other, ResetPassword::class);
    }

    public function test_setup_link_failure_keeps_the_appointment_and_can_be_retried(): void
    {
        $this->signInAdmin();
        $this->createCommittee('heritage')->assertCreated();
        $calls = 0;
        $broker = Mockery::mock(PasswordBroker::class);
        $broker->shouldReceive('sendResetLink')->andReturnUsing(function () use (&$calls): string {
            if (++$calls === 1) {
                throw new RuntimeException('SMTP unavailable');
            }

            return Password::RESET_LINK_SENT;
        });
        Password::shouldReceive('broker')->andReturn($broker);

        $response = $this->appoint('heritage', 'mail.fails@example.org')->assertCreated()->assertJsonPath('appointment.setup_link', 'failed');
        $this->assertStringContainsString('تعذّر إرسال', (string) $response->json('message'));
        $this->assertSame(1, DB::table('committee_members')->count(), 'the appointment is kept');
        $member = (int) $response->json('data.1.committee.members.0.id');
        $this->adminPost("/committees/heritage/members/{$member}/setup-link", [])->assertOk()->assertJsonPath('code', 'sent');
        $this->adminPost("/committees/creativity/members/{$member}/setup-link", [])->assertConflict();
    }

    public function test_approval_needs_three_or_five_current_judges_and_changes_need_a_draft(): void
    {
        $this->signInAdmin();
        $this->createCommittee('creativity')->assertCreated();
        foreach (['a', 'b'] as $name) {
            $this->appoint('creativity', "{$name}@judges.example.org")->assertCreated();
        }
        $this->approve('creativity')->assertUnprocessable()->assertJsonPath('code', 'wrong_size');
        $this->appoint('creativity', 'c@judges.example.org')->assertCreated();
        $this->approve('creativity')->assertOk()->assertJsonPath('data.2.committee.status', 'approved');
        $this->assertSame((int) $this->admin->id, (int) DB::table('committees')->value('approved_by'));

        // Approved: membership does not change until it returns to draft.
        $this->appoint('creativity', 'd@judges.example.org')->assertConflict()->assertJsonPath('code', 'committee_approved');
        $first = (int) DB::table('committee_members')->orderBy('id')->value('id');
        $this->adminPost("/committees/creativity/members/{$first}/end", [])->assertConflict()->assertJsonPath('code', 'committee_approved');
        $this->adminPost('/committees/creativity/return-to-draft', [])->assertOk()->assertJsonPath('data.2.committee.status', 'draft');
        $this->appoint('creativity', 'd@judges.example.org')->assertCreated();
        $this->approve('creativity')->assertUnprocessable();
        $this->appoint('creativity', 'e@judges.example.org')->assertCreated();
        $this->approve('creativity')->assertOk();
        $this->adminPost('/committees/creativity/return-to-draft', [])->assertOk();

        // Ending keeps the row and revokes the grant; the count drops to 4.
        $ended = $this->adminPost("/committees/creativity/members/{$first}/end", [])->assertOk();
        $this->assertSame(4, $ended->json('data.2.committee.current_count'));
        $this->assertCount(1, $ended->json('data.2.committee.former_members'));
        $this->assertNotNull(DB::table('committee_members')->where('id', $first)->value('left_at'));
        $this->assertSame(1, DB::table('cycle_user_roles')->whereNotNull('revoked_at')->whereNotNull('revocation_reason')->count());
        $this->approve('creativity')->assertUnprocessable();

        // Approval picks up no pending submission; once one is referred, judging starts, so the committee can no longer return to draft.
        $this->appoint('creativity', 'f@judges.example.org')->assertCreated();
        $this->approve('creativity')->assertOk();
        $submitted = $this->submitApplication($this->candidate);
        $this->signInAdmin();
        $this->assertSame(0, DB::table('judging_assignments')->count());
        $this->refer($submitted['id'])->assertOk();
        $this->assertGreaterThan(0, DB::table('judging_assignments')->count());
        $this->adminPost('/committees/creativity/return-to-draft', [])->assertConflict()->assertJsonPath('code', 'judging_history');
        $this->assertSame('approved', DB::table('committees')->value('status'));
    }

    public function test_appointments_and_submissions_respect_the_conflict_of_interest_bar(): void
    {
        // A candidate who submitted in this cycle is not appointed; the application is untouched.
        $submitted = $this->submitApplication($this->candidate);
        $this->signInAdmin();
        $this->createCommittee('creativity')->assertCreated();
        $before = DB::table('applications')->where('id', $submitted['id'])->first();
        $this->appoint('creativity', $this->candidate->email, existing: true)->assertConflict()->assertJsonPath('code', 'submitted_candidate');
        $this->assertEquals($before, DB::table('applications')->where('id', $submitted['id'])->first());
        $this->assertSame(0, DB::table('committee_members')->count());

        // A judge of this cycle cannot submit here; once the membership ends, they can.
        $judge = $this->person('judge@example.org');
        $ready = $this->readyToSubmit($judge);
        $this->signInAdmin();
        $member = (int) $this->appoint('creativity', $judge->email, existing: true)->assertCreated()->json('data.2.committee.members.0.id');
        $this->signIn($judge->email);
        $this->read('/api/user')->assertJsonPath('data.is_judge', true)->assertJsonPath('data.is_candidate', false);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 1], $ready)->assertForbidden()->assertJsonPath('code', 'not_candidate');
        $this->assertSame(1, DB::table('application_versions')->count());
        $this->signInAdmin();
        $this->adminPost("/committees/creativity/members/{$member}/end", [])->assertOk();
        $this->assertTrue(app(CandidateRole::class)->isHeldBy($judge), 'ending the last judgeship restores the candidate classification');
        $this->assertFalse(app(AdminAccess::class)->isJudge((int) $judge->id));
        $this->signIn($judge->email);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 1], $ready)->assertCreated();

        // A current administrator cannot submit; candidate history and roles stay.
        $staff = $this->person('staff@example.org');
        $staffReady = $this->readyToSubmit($staff);
        $this->artisan('admin:grant-access', ['--email' => $staff->email])->assertSuccessful();
        $this->signIn($staff->email);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 1], $staffReady)->assertForbidden()->assertJsonPath('code', 'award_admin');
        $this->assertSame(2, DB::table('application_versions')->count());
        $this->assertTrue(app(CandidateRole::class)->isHeldBy($staff));
        $this->assertSame(1, DB::table('applications')->where('created_by', $staff->id)->count());
    }

    public function test_registered_users_are_listed_once_with_progress_from_stored_records(): void
    {
        User::factory()->candidate()->unverified()->create(['email' => 'fresh@example.org']);
        $basic = $this->person('basic@example.org');
        $this->completeStage1($basic);
        $nominated = $this->person('nominated@example.org');
        $this->completeStage1($nominated);
        $this->signIn($nominated->email);
        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $this->readyToSubmit($this->person('uploading@example.org'), confirmStage3: false);
        $this->readyToSubmit($this->person('ready@example.org'));
        // Confirmed, then a link saved later: the confirmation is stale.
        $staleKey = $this->readyToSubmit($this->person('stale@example.org'));
        $this->send('/api/candidate/documentation/links', ['links' => [['title' => 'رابط', 'url' => 'https://example.org']]], $staleKey)->assertOk();
        $this->submitApplication($this->candidate);
        $this->artisan('admin:grant-access', ['--email' => $this->candidate->email])->assertSuccessful();
        User::factory()->candidate()->create(['email' => 'off@example.org', 'status' => 'disabled', 'disabled_at' => now()]);

        $this->signInAdmin();
        $this->createCommittee('creativity')->assertCreated();
        $this->appoint('creativity', 'judge@example.org')->assertCreated();
        $gone = (int) $this->appoint('creativity', 'gone@example.org')->json('data.2.committee.members.1.id');
        $this->adminPost("/committees/creativity/members/{$gone}/end", [])->assertOk();
        // An expired judge grant is not a current role.
        DB::table('cycle_user_roles')->insert(['cycle_id' => $this->cycleId, 'user_id' => $basic->id, 'role_id' => app(AdminAccess::class)->roleId('judge'),
            'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);

        $all = $this->read("/api/admin/users?cycle={$this->cycleId}&per_page=50")->assertOk();
        $rows = collect($all->json('data'))->keyBy('email');
        $this->assertSame(User::query()->count(), $all->json('meta.total'), 'every account, once');
        $this->assertCount($all->json('meta.total'), $rows);
        $expected = [
            'fresh@example.org' => ['no_basic_information', 'none', 'none', 'none'],
            'basic@example.org' => ['awaiting_nomination', 'complete', 'none', 'none'],
            'nominated@example.org' => ['awaiting_documentation', 'complete', 'complete', 'none'],
            'uploading@example.org' => ['documentation_incomplete', 'complete', 'complete', 'incomplete'],
            'ready@example.org' => ['ready', 'complete', 'complete', 'complete'],
            'stale@example.org' => ['documentation_incomplete', 'complete', 'complete', 'incomplete'],
            'layla@example.org' => ['submitted', 'complete', 'complete', 'complete'],
            'off@example.org' => ['no_basic_information', 'none', 'none', 'none'],
            'admin@example.org' => ['not_required', 'none', 'none', 'none'],
            'operator@example.org' => ['not_required', 'none', 'none', 'none'],
            'judge@example.org' => ['not_required', 'none', 'none', 'none'],
        ];
        foreach ($expected as $email => $progress) {
            $this->assertSame($progress, array_values($rows[$email]['progress']), $email);
        }
        $this->assertFalse($rows['fresh@example.org']['email_verified']);
        $this->assertFalse($rows['off@example.org']['active']);
        $this->assertNull($rows['fresh@example.org']['last_login_at']);
        $this->assertSame(['award_admin', 'candidate'], array_column($rows['layla@example.org']['roles']['platform'], 'code'), 'candidate history kept beside the new role');
        $this->assertNotNull($rows['layla@example.org']['submission']['application_id']);
        $this->assertSame([['judge', 'الإبداع']], array_map(fn ($role) => [$role['code'], $role['domain']], $rows['judge@example.org']['roles']['cycle']));
        $this->assertSame([], $rows['gone@example.org']['roles']['cycle'], 'a revoked grant is not shown');
        $this->assertSame([], $rows['basic@example.org']['roles']['cycle'], 'an expired grant is not shown');

        // Every filter agrees with the labels shown.
        $emails = fn (string $query): array => collect($this->read("/api/admin/users?cycle={$this->cycleId}&per_page=50&{$query}")->assertOk()->json('data'))->pluck('email')->sort()->values()->all();
        foreach (array_unique(array_column(array_column($rows->all(), 'progress'), 'state')) as $state) {
            $this->assertSame($rows->filter(fn ($row) => $row['progress']['state'] === $state)->keys()->sort()->values()->all(), $emails("progress={$state}"), $state);
        }
        $this->assertSame($rows->filter(fn ($row) => ! $row['email_verified'])->keys()->sort()->values()->all(), $emails('verified=unverified'));
        $this->assertSame(['off@example.org'], $emails('state=disabled'));
        $this->assertSame(['admin@example.org', 'layla@example.org'], $emails('role=award_admin'));
        $this->assertSame(['judge@example.org'], $emails('role=judge'));
        $this->assertSame(['stale@example.org'], $emails('q=STALE'));
        $page = $this->read("/api/admin/users?cycle={$this->cycleId}&per_page=10&page=2&sort=name&direction=asc")->assertOk();
        $this->assertSame([2, $rows->count()], [$page->json('meta.page'), $page->json('meta.total')]);
        $this->assertCount($rows->count() - 10, $page->json('data'));
        $this->read('/api/admin/users?sort=password')->assertUnprocessable();

        // Another cycle: nobody is dropped, nothing from 2026 is counted; the closed cycle keeps its history.
        $this->runSetup('cycle-2026', ['--close' => true]);
        $this->configureCycle('cycle-2027');
        $next = (int) DB::table('award_cycles')->where('code', 'cycle-2027')->value('id');
        $other = $this->read("/api/admin/users?cycle={$next}&per_page=50")->assertOk()->assertJsonPath('meta.cycle.stage_open', true);
        $this->assertSame($rows->count(), $other->json('meta.total'));
        $otherRows = collect($other->json('data'))->keyBy('email');
        foreach (['basic', 'nominated', 'uploading', 'ready', 'stale', 'layla'] as $name) {
            $this->assertSame('awaiting_nomination', $otherRows["{$name}@example.org"]['progress']['state'], $name);
        }
        $this->assertNull($otherRows['layla@example.org']['submission']);
        $this->assertSame([], collect($this->read("/api/admin/users?cycle={$next}&role=judge")->json('data'))->pluck('email')->all(), 'judge is cycle-scoped');
        $closed = collect($this->read("/api/admin/users?cycle={$this->cycleId}&per_page=50")->assertJsonPath('meta.cycle.stage_open', false)->json('data'))->keyBy('email');
        $this->assertSame(['submitted', 'ready'], [$closed['layla@example.org']['progress']['state'], $closed['ready@example.org']['progress']['state']]);
        $this->read('/api/admin/users?cycle=999')->assertNotFound();
    }

    public function test_the_users_list_is_refused_to_others_and_carries_no_private_fields(): void
    {
        $this->submitApplication($this->candidate);
        $judge = $this->person('judge@example.org');
        $this->completeStage1($judge);
        $this->signInAdmin();
        $this->createCommittee('heritage')->assertCreated();
        $this->appoint('heritage', $judge->email, existing: true)->assertCreated();

        $response = $this->read('/api/admin/users?per_page=50')->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(
            ['id', 'name', 'email', 'email_verified', 'active', 'registered_at', 'last_login_at', 'roles', 'progress', 'submission'],
            array_keys($response->json('data.0')),
        );
        $body = (string) $response->getContent();
        foreach ([self::NATIONAL_ID, '1500.50', 'مبادرة ذاكرة الحيّ', 'حي القيمرية', 'password', 'remember_token', 'storage_path', '1990-04-02'] as $private) {
            $this->assertStringNotContainsString($private, $body, $private);
        }
        $this->navigate('/admin/users')->assertOk();

        foreach ([$this->candidate->email, $judge->email] as $email) {
            $this->signIn($email);
            $this->read('/api/admin/users')->assertForbidden()->assertJsonPath('code', 'not_admin');
            $this->navigate('/admin/users')->assertForbidden();
        }
    }

    public function test_reports_and_logs_are_refused_without_their_permissions(): void
    {
        $this->signIn();
        foreach (['/api/admin/exports/users?format=xlsx', '/api/admin/log/audit', '/api/admin/log/system', '/api/admin/applications/1/card'] as $uri) {
            $this->read($uri)->assertForbidden()->assertJsonPath('code', 'not_admin');
        }

        // An account allowed to view users, but not to export, and not the log.
        $viewer = User::factory()->create(['email' => 'viewer@example.org']);
        $role = DB::table('roles')->insertGetId(['code' => 'users_viewer', 'name_ar' => 'مطّلع', 'scope' => 'platform', 'created_at' => now(), 'updated_at' => now()]);
        $grant = fn (string $code) => DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'created_at' => now()]);
        $grant(AdminAccess::VIEW_USERS);
        DB::table('user_roles')->insert(['user_id' => $viewer->id, 'role_id' => $role, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($viewer->email);
        $this->read('/api/admin/users')->assertOk();
        $this->read('/api/admin/exports/users?format=xlsx')->assertForbidden();
        // reports.export alone does not open another module's data.
        $grant(AdminAccess::EXPORT_REPORTS);
        $this->read('/api/admin/exports/audit?format=xlsx')->assertForbidden();
        $this->read('/api/admin/exports/applications?format=xlsx')->assertForbidden();
        $this->read('/api/admin/log/audit')->assertForbidden();
        $this->browser('GET', '/api/admin/exports/users?format=xlsx', headers: ['X-Candidate-Context' => 'stale'])->assertConflict()->assertJsonPath('code', 'account_mismatch');
        $this->read('/api/admin/exports/users?format=csv')->assertUnprocessable();
        $this->read('/api/admin/exports/nothing?format=xlsx')->assertNotFound();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'report.exported')->count());
    }

    public function test_exports_cover_every_matching_row_with_the_table_rules_and_safe_text(): void
    {
        foreach (range(1, 30) as $index) {
            User::factory()->candidate()->create([
                'email' => sprintf('person%02d@example.org', $index),
                'name' => $index === 7 ? '=HYPERLINK("http://evil.example","x")' : "شخص {$index}",
            ]);
        }
        $this->signInAdmin();
        $query = "cycle={$this->cycleId}&progress=no_basic_information&sort=name&direction=asc";

        // The table, page by page, is the reference.
        $first = $this->read("/api/admin/users?{$query}&per_page=10")->assertOk();
        $this->assertGreaterThan(25, $first->json('meta.total'));
        $table = collect(range(1, $first->json('meta.last_page')))
            ->flatMap(fn (int $page) => $this->read("/api/admin/users?{$query}&per_page=10&page={$page}")->json('data'))->pluck('email')->all();

        $response = $this->read("/api/admin/exports/users?format=xlsx&{$query}&page=2&per_page=10")->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertStringStartsWith(realpath(storage_path('app/private/exports')), realpath($path));
        $sheet = $this->sheetRows($path);
        $heading = array_search('الاسم', array_map(fn (array $row) => $row[0] ?? null, $sheet), true);
        $exported = array_map(fn (array $row) => $row[1], array_slice($sheet, $heading + 1));
        $this->assertSame($table, $exported, 'every matching row, in the table order, not only one page');
        $scope = array_column(array_slice($sheet, 1, $heading - 1), 1, 0);
        $this->assertSame('لم يستكمل البيانات الأساسية', $scope['تقدّم الترشح']);
        $this->assertSame(count($table), $scope['عدد الصفوف']);

        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $xml, 'no formula cells');
        $this->assertStringContainsString('=HYPERLINK', html_entity_decode($xml));
        $this->assertStringContainsString('rightToLeft="true"', $xml);
        $this->assertStringContainsString('frozen', $xml);
        $this->assertStringContainsString('<autoFilter', $xml);
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();
        $this->assertFileDoesNotExist($path, 'the temporary file goes once sent');

        $pdf = $this->read("/api/admin/exports/users?format=pdf&{$query}")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertSame([$this->cycleId, $this->cycleId], DB::table('audit_logs')->where('action', 'report.exported')->pluck('cycle_id')->map(fn ($id) => (int) $id)->all());

        // Above the PDF limit: refused, nothing truncated, nothing recorded.
        DB::table('users')->insert(array_map(fn (int $index) => [
            'name' => "حساب {$index}", 'email' => "bulk{$index}@example.org", 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], range(1, 1001)));
        $this->read("/api/admin/exports/users?format=pdf&cycle={$this->cycleId}")->assertUnprocessable()
            ->assertJsonPath('code', 'export_too_large')->assertJsonPath('limit', 1000);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'report.exported')->count());
    }

    public function test_a_committee_title_with_forbidden_sheet_characters_exports_with_its_full_title(): void
    {
        $name = "لجنة: التراث/الفنون [أ] *؟? \\ 'الأولى'";
        $this->signInAdmin();
        $this->adminPost('/committees', ['domain' => 'creativity', 'name' => $name])->assertCreated();
        $query = "cycle={$this->cycleId}&domain=creativity";

        $response = $this->read("/api/admin/exports/committee-members?format=xlsx&{$query}")->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();
        $sheet = 'أعضاء لجنة التراث الفنون أ ؟ ال';
        $this->assertSame(31, mb_strlen($sheet));
        $this->assertSame($sheet, XlsxWriter::sheetName("أعضاء {$name}"));
        $this->assertStringContainsString("<sheet name=\"{$sheet}\"", $workbook);
        $this->assertStringContainsString("'{$sheet}'!\$A\$", $workbook, 'the filter reference quotes a name without inner apostrophes');
        $this->assertSame("أعضاء {$name}", $this->sheetRows($path)[0][0], 'the full title stays in the report');
        @unlink($path);

        $this->read("/api/admin/exports/committee-members?format=pdf&{$query}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_report_event_is_recorded_once_and_only_after_its_file_was_generated(): void
    {
        $this->signInAdmin();
        $query = "cycle={$this->cycleId}";
        $events = fn () => DB::table('audit_logs')->where('action', 'report.exported')->get();
        $exports = fn () => File::exists(storage_path('app/private/exports')) ? count(File::files(storage_path('app/private/exports'))) : 0;
        $leftovers = $exports();

        // A rendering failure and a failed file write record nothing and leave no file.
        $this->app->instance(PdfWriter::class, new class
        {
            public function table(TableReport $report): string
            {
                throw new RuntimeException('rendering failed');
            }
        });
        $this->read("/api/admin/exports/users?format=pdf&{$query}")->assertServerError();
        $this->app->instance(XlsxWriter::class, new class
        {
            public function write(TableReport $report, string $path): void
            {
                file_put_contents($path, 'partial');

                throw new RuntimeException('disk full');
            }
        });
        $this->read("/api/admin/exports/users?format=xlsx&{$query}")->assertServerError();
        $this->assertCount(0, $events());
        $this->assertSame($leftovers, $exports(), 'the partial workbook was removed');

        // A generated file: exactly one event each, with the actor, cycle and safe metadata.
        $this->app->forgetInstance(PdfWriter::class);
        $this->app->forgetInstance(XlsxWriter::class);
        $this->read("/api/admin/exports/users?format=pdf&{$query}")->assertOk();
        $workbook = $this->read("/api/admin/exports/users?format=xlsx&{$query}")->assertOk();
        @unlink($workbook->baseResponse->getFile()->getPathname());

        $recorded = $events();
        $this->assertCount(2, $recorded);
        $rows = DB::table('users')->count();
        foreach (['pdf', 'xlsx'] as $index => $format) {
            $this->assertSame($this->admin->id, (int) $recorded[$index]->actor_user_id);
            $this->assertSame($this->cycleId, (int) $recorded[$index]->cycle_id);
            $this->assertNull($recorded[$index]->subject_type);
            $this->assertSame(['report' => 'users', 'format' => $format, 'rows' => $rows], json_decode($recorded[$index]->after_data, true));
        }
    }

    public function test_a_submission_waits_for_the_administration_and_a_referral_reaches_only_its_own_committee_once(): void
    {
        [$creativityJudges, $creativity] = $this->approvedCommittee('creativity', ['c1', 'c2', 'c3']);
        [$heritageJudges, $heritage] = $this->approvedCommittee('heritage', ['h1', 'h2', 'h3']);
        $referred = $this->submitApplication($this->candidate);
        $excluded = $this->submitApplication($this->person('omar@example.org'), ['title' => 'عمل سيُستبعد']);
        $waiting = $this->submitApplication($this->person('rana@example.org'), ['domain' => 'heritage', 'subcategory' => 'tangible_heritage', 'title' => 'عمل قيد الانتظار']);

        // Submission and approval assign nothing: every submission is pending.
        $this->assertSame(0, DB::table('judging_assignments')->count());
        $this->assertSame(['pending', 'pending', 'pending'], DB::table('applications')->orderBy('id')->pluck('review_status')->all());
        $this->signIn($this->candidate->email);
        $this->read('/api/candidate/review')->assertOk()->assertJsonPath('data.submission.processing', 'pending');

        $this->signInAdmin();
        $page = $this->read("/api/admin/applications/{$referred['id']}")->assertOk()
            ->assertJsonPath('data.review.status', 'pending')->assertJsonPath('data.review.label', 'لم يُصنّف بعد')
            ->assertJsonPath('data.review.target.committee.id', $creativity)->assertJsonPath('data.review.target.refusal', null);
        $this->assertSame('creativity', $page->json('data.summary.domain.code'));

        // The confirmed committee must still be the destination.
        $this->review($referred['id'], 'refer', ['committee_id' => $heritage])->assertConflict()->assertJsonPath('code', 'committee_changed');
        $this->assertSame(0, DB::table('judging_assignments')->count());

        $this->review($referred['id'], 'refer', ['committee_id' => $creativity])->assertOk()->assertJsonPath('code', 'referred')
            ->assertJsonPath('data.status', 'referred')->assertJsonPath('data.committee.id', $creativity)->assertJsonPath('data.decided_by', 'مدير الجائزة');
        $this->review($referred['id'], 'refer', ['committee_id' => $creativity])->assertOk()->assertJsonPath('code', 'unchanged');
        $this->assertSame([$creativity], DB::table('judging_assignments')->distinct()->pluck('committee_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$referred['id']], DB::table('judging_assignments')->distinct()->pluck('application_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(3, DB::table('judging_assignments')->count(), 'one per current member, created once');
        $row = DB::table('applications')->where('id', $referred['id'])->first();
        $this->assertSame(['referred', 'admin', $this->admin->id, $creativity, 'in_judging'], [$row->review_status, $row->review_source, (int) $row->review_decided_by, (int) $row->review_committee_id, $row->status]);
        $event = DB::table('audit_logs')->where('action', 'application.referred')->sole();
        $this->assertSame([$this->admin->id, 'application', $referred['id'], $this->cycleId], [(int) $event->actor_user_id, $event->subject_type, (int) $event->subject_id, (int) $event->cycle_id]);

        // The opposite decision is refused, both ways.
        $this->review($referred['id'], 'exclude', ['reason' => 'خارج الشروط'])->assertConflict()->assertJsonPath('code', 'already_decided');
        $this->review($excluded['id'], 'exclude', ['reason' => '  '])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->review($excluded['id'], 'exclude', ['reason' => 'العمل خارج مجالات الدورة.'])->assertOk()->assertJsonPath('code', 'excluded')
            ->assertJsonPath('data.label', 'مستبعد — مؤرشف')->assertJsonPath('data.reason', 'العمل خارج مجالات الدورة.');
        $this->review($excluded['id'], 'exclude', ['reason' => 'سبب آخر'])->assertOk()->assertJsonPath('code', 'unchanged')->assertJsonPath('data.reason', 'العمل خارج مجالات الدورة.');
        $this->review($excluded['id'], 'refer', ['committee_id' => $creativity])->assertConflict()->assertJsonPath('code', 'already_decided');
        $this->assertSame(0, DB::table('judging_assignments')->where('application_id', $excluded['id'])->count());
        // Never sent to judging: no judge is listed for it, unlike the referred one.
        $this->read("/api/admin/applications/{$excluded['id']}")->assertJsonPath('data.judging.referred', false)->assertJsonPath('data.judging.scores', []);
        $this->read("/api/admin/applications/{$referred['id']}")->assertJsonPath('data.judging.ready', true)->assertJsonCount(3, 'data.judging.scores');
        $exclusion = DB::table('audit_logs')->where('action', 'application.excluded')->sole();
        $this->assertSame([$this->admin->id, $excluded['id'], $this->cycleId], [(int) $exclusion->actor_user_id, (int) $exclusion->subject_id, (int) $exclusion->cycle_id]);
        $this->assertStringNotContainsString('خارج مجالات', (string) $exclusion->after_data, 'the reason is not copied into the log');

        // Archived, not hidden: every classification stays in the table and its exports.
        $this->assertSame(['excluded', 'pending', 'referred'], collect($this->read("/api/admin/applications?cycle={$this->cycleId}")->assertOk()->json('data'))->pluck('review')->sort()->values()->all());
        $this->read("/api/admin/applications?cycle={$this->cycleId}&review=excluded")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $excluded['id']);
        $this->read('/api/admin/applications?review=unknown')->assertUnprocessable();
        $path = $this->read("/api/admin/exports/applications?format=xlsx&cycle={$this->cycleId}&review=excluded")->assertOk()->baseResponse->getFile()->getPathname();
        $sheet = $this->sheetRows($path);
        @unlink($path);
        $this->assertSame('مستبعد — مؤرشف', collect($sheet)->firstWhere(0, 'التصنيف الإداري')[1] ?? null, 'the filter is stated');
        $this->assertSame(1, collect($sheet)->filter(fn (array $cells) => in_array('مستبعد — مؤرشف', $cells, true) && in_array($excluded['reference_number'], $cells, true))->count());
        $this->read("/api/admin/applications/{$excluded['id']}/card")->assertOk();

        // Judges: only the referred application, through every endpoint.
        $this->signIn($creativityJudges[0]->email);
        $this->read('/api/judge/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $referred['id']);
        $this->read("/api/judge/projects/{$referred['id']}")->assertOk()->assertJsonMissingPath('data.review');
        foreach ([[$creativityJudges[0], $excluded['id']], [$heritageJudges[0], $waiting['id']]] as [$judge, $blocked]) {
            $this->signIn($judge->email);
            $this->read('/api/judge/projects')->assertOk()->assertJsonMissing(['id' => $blocked]);
            $this->read("/api/judge/projects/{$blocked}")->assertNotFound();
            $link = (int) DB::table('file_links')->where('application_version_id', DB::table('applications')->where('id', $blocked)->value('current_version_id'))->value('id');
            $this->browser('GET', "/api/judge/projects/{$blocked}/files/{$link}", ['account_context' => AccountContext::for($judge)])->assertNotFound();
            $this->browser('POST', "/api/judge/projects/{$blocked}/evaluation", [
                'account_context' => AccountContext::for($judge), 'score' => '50', 'notes' => 'ملاحظة', 'recommendations' => 'توصية', 'excluded' => false,
            ])->assertNotFound();
        }
        $this->assertSame(0, DB::table('evaluations')->count());

        // The owner sees a truthful status, never the reason.
        $this->signIn('omar@example.org');
        $review = $this->read('/api/candidate/review')->assertOk()->assertJsonPath('data.submission.processing', 'excluded');
        $this->assertStringNotContainsString('خارج مجالات', (string) $review->getContent());
    }

    public function test_review_decisions_need_their_permission_a_ready_committee_and_the_displayed_account(): void
    {
        $pending = $this->submitApplication($this->candidate, ['domain' => 'culture_and_arts', 'subcategory' => 'culture']);
        $this->signInAdmin();

        // No committee, then a draft one: explained, and the application stays pending.
        $this->read("/api/admin/applications/{$pending['id']}")->assertJsonPath('data.review.target.refusal', 'committee_missing');
        $this->review($pending['id'], 'refer', ['committee_id' => 1])->assertConflict()->assertJsonPath('code', 'committee_missing');
        $this->adminPost('/committees', ['domain' => 'culture_and_arts', 'name' => 'لجنة الثقافة'])->assertCreated();
        $committee = (int) DB::table('committees')->value('id');
        $this->read("/api/admin/applications/{$pending['id']}")->assertJsonPath('data.review.target.refusal', 'committee_not_approved');
        $this->review($pending['id'], 'refer', ['committee_id' => $committee])->assertConflict()->assertJsonPath('code', 'committee_not_approved');
        $this->assertSame('pending', DB::table('applications')->where('id', $pending['id'])->value('review_status'));

        // Another account's page, and a missing application.
        $this->browser('POST', "/api/admin/applications/{$pending['id']}/review/exclude", ['account_context' => 'stale', 'reason' => 'سبب'])
            ->assertConflict()->assertJsonPath('code', 'account_mismatch');
        $this->review(999999, 'exclude', ['reason' => 'سبب'])->assertNotFound();

        // Without applications.review (viewing alone is not enough).
        $permission = DB::table('permissions')->where('code', AdminAccess::REVIEW_APPLICATIONS)->value('id');
        DB::table('role_permissions')->where('permission_id', $permission)->delete();
        $this->read("/api/admin/applications/{$pending['id']}")->assertOk();
        $this->review($pending['id'], 'exclude', ['reason' => 'سبب'])->assertForbidden();

        // Candidates and judges.
        $judge = User::factory()->create(['email' => 'judge@example.org']);
        $this->appoint('culture_and_arts', $judge->email, existing: true)->assertCreated();
        foreach ([$this->candidate, $judge] as $user) {
            $this->signIn($user->email);
            $this->browser('POST', "/api/admin/applications/{$pending['id']}/review/exclude", ['account_context' => AccountContext::for($user), 'reason' => 'سبب'])->assertForbidden();
        }
        $this->assertSame([0, 0], [DB::table('audit_logs')->whereIn('action', ['application.referred', 'application.excluded'])->count(), DB::table('judging_assignments')->count()]);
        $this->assertSame('pending', DB::table('applications')->where('id', $pending['id'])->value('review_status'));
    }

    public function test_the_backfill_keeps_existing_judging_history_as_a_legacy_referral(): void
    {
        [$judges, $committee] = $this->approvedCommittee('creativity', ['l1', 'l2', 'l3']);
        $judged = $this->submitApplication($this->candidate);
        $waiting = $this->submitApplication($this->person('omar@example.org'), ['title' => 'لم يُحكَّم']);
        $this->readyToSubmit($this->person('draft@example.org'), ['title' => 'مسودة'], confirmStage3: false);
        $this->signInAdmin();
        $this->review($judged['id'], 'refer', ['committee_id' => $committee])->assertOk();
        $this->signIn($judges[0]->email);
        $this->browser('POST', "/api/judge/projects/{$judged['id']}/evaluation", [
            'account_context' => AccountContext::for($judges[0]), 'score' => null, 'notes' => 'مخالفة', 'recommendations' => 'يُستبعد', 'excluded' => true,
        ])->assertOk();
        $history = [DB::table('judging_assignments')->orderBy('id')->get(), DB::table('evaluations')->get(), DB::table('evaluation_scores')->get()];
        $firstAssigned = DB::table('judging_assignments')->min('assigned_at');

        // The state before the classification existed: history, no classification.
        DB::table('applications')->update(['review_status' => null, 'review_source' => null, 'review_decided_by' => null, 'review_decided_at' => null, 'review_reason' => null, 'review_committee_id' => null]);
        DB::table('applications')->where('id', $waiting['id'])->update(['status' => 'in_judging']);
        $migration = require database_path('migrations/2026_10_29_900001_add_application_review_classification.php');

        $counts = $migration->backfill();

        $this->assertSame(['pending' => 1, 'legacy_referred' => 1, 'in_judging_without_assignments' => 1, 'assignments_in_several_committees' => 0], $counts);
        $row = DB::table('applications')->where('id', $judged['id'])->first();
        $this->assertSame(['referred', 'legacy', null, $committee], [$row->review_status, $row->review_source, $row->review_decided_by, (int) $row->review_committee_id], 'no administrator is invented');
        $this->assertEquals($firstAssigned, $row->review_decided_at, 'the time of the first real assignment');
        $this->assertSame('pending', DB::table('applications')->where('id', $waiting['id'])->value('review_status'), "a judge's exclusion is not an administrative one");
        $this->assertNull(DB::table('applications')->where('title', 'مسودة')->value('review_status'), 'drafts stay unclassified');
        $this->assertEquals($history, [DB::table('judging_assignments')->orderBy('id')->get(), DB::table('evaluations')->get(), DB::table('evaluation_scores')->get()]);
        $this->assertSame('exclude', DB::table('evaluations')->value('recommendation'));
        $this->assertSame(['pending' => 0, 'legacy_referred' => 0, 'in_judging_without_assignments' => 0, 'assignments_in_several_committees' => 0], $migration->backfill(), 'classified applications are left alone');

        // The legacy referral still reaches its judges; the admin page says it is legacy.
        $this->signIn($judges[1]->email);
        $this->read('/api/judge/projects')->assertOk()->assertJsonPath('data.0.id', $judged['id']);
        $this->signInAdmin();
        $this->read("/api/admin/applications/{$judged['id']}")->assertJsonPath('data.review.legacy', true)->assertJsonPath('data.review.decided_by', null);
    }

    public function test_the_historical_purge_removes_only_the_retired_values_and_rehashes_the_snapshot(): void
    {
        [, $committee] = $this->approvedCommittee('creativity', ['p1', 'p2', 'p3']);
        $submitted = $this->submitApplication($this->candidate);
        $this->readyToSubmit($this->person('draft@example.org'), ['title' => 'مسودة'], confirmStage3: false);
        $this->signInAdmin();
        $this->review($submitted['id'], 'refer', ['committee_id' => $committee])->assertOk();
        $draft = (int) DB::table('applications')->where('title', 'مسودة')->value('id');

        // What the earlier form stored, written as it was then.
        $version = DB::table('application_versions')->sole();
        $legacy = json_decode((string) $version->data_snapshot, true);
        $legacy['schema'] = 'candidate-submission/1';
        $legacy['nomination_details'] += [
            'implementation' => ['state' => 'ongoing', 'started_on' => '2021-09-15', 'ended_on' => null],
            'locations' => [['country_code' => 'SY', 'governorate' => 'SY-DI', 'governorate_name' => 'دمشق', 'locality' => 'دمشق القديمة', 'address' => 'حي القيمرية']],
            'funding' => ['type' => 'fully_funded', 'funder_name' => 'مؤسسة الروّاد', 'amount_usd' => '1500.50'],
            'partnerships' => ['has_partnerships' => true, 'items' => [['name' => 'جمعية العاديات', 'contribution' => 'المكان']]],
        ];
        $oldHash = CanonicalJson::hash($legacy);
        DB::table('application_versions')->where('id', $version->id)->update(['data_snapshot' => json_encode($legacy, JSON_UNESCAPED_UNICODE), 'content_hash' => $oldHash]);
        foreach ([$submitted['id'], $draft] as $application) {
            DB::table('application_locations')->insert(['application_id' => $application, 'country_code' => 'SY', 'governorate' => 'SY-DI', 'locality' => 'دمشق القديمة']);
            DB::table('application_partnerships')->insert(['application_id' => $application, 'position' => 1, 'partner_name' => 'جمعية العاديات']);
        }
        $confirmed = DB::table('audit_logs')->where('action', 'nomination_details.confirmed')->orderBy('id')->get();
        foreach ($confirmed as $row) {
            DB::table('audit_logs')->where('id', $row->id)->update(['after_data' => json_encode(json_decode((string) $row->after_data, true) + ['funding_type' => 'fully_funded'], JSON_UNESCAPED_UNICODE)]);
        }
        $unrelated = DB::table('audit_logs')->insertGetId(['action' => 'award_cycle.geography_set', 'subject_type' => 'award_cycle', 'subject_id' => $this->cycleId,
            'after_data' => json_encode(['implementation_country' => 'SY', 'funding_type' => 'kept']), 'occurred_at' => now()->format('Y-m-d H:i:s.u')]);
        $retiredColumns = ['funding_type' => 'fully_funded', 'funder_name' => 'مؤسسة الروّاد', 'funding_amount_usd' => '1500.50', 'has_partnerships' => true,
            'implementation_started_on' => '2021-09-15', 'implementation_status' => 'ongoing'];
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // The retired columns refuse any new value on these engines.
            try {
                DB::table('applications')->where('id', $draft)->update(['funding_type' => 'self_funded']);
                $this->fail('chk_applications_retired_work should refuse the value');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        } else {
            DB::table('applications')->whereIn('id', [$submitted['id'], $draft])->update($retiredColumns);
        }
        $kept = [DB::table('applications')->where('id', $submitted['id'])->first(['title', 'purpose_and_mission', 'objectives', 'documented_impact', 'status', 'review_status', 'reference_number', 'first_submitted_at']),
            DB::table('judging_assignments')->orderBy('id')->get(), DB::table('file_links')->orderBy('id')->get(), DB::table('candidate_profiles')->get(), DB::table('applicant_individual_details')->get()];

        $migration = require database_path('migrations/2026_10_29_900003_purge_retired_nomination_fields.php');
        $counts = $migration->purge();

        $this->assertSame(['locations' => 2, 'partnerships' => 2, 'versions' => 1, 'form_snapshots' => 0, 'audit_rows' => count($confirmed), 'unverified_old_hashes' => 0],
            array_diff_key($counts, ['applications' => 0]));
        foreach (DB::table('applications')->get() as $application) {
            foreach (array_keys($retiredColumns) + ['implementation_ended_on'] as $column) {
                $this->assertNull($application->{$column}, $column);
            }
        }
        $this->assertSame([0, 0], [DB::table('application_locations')->count(), DB::table('application_partnerships')->count()]);

        $after = DB::table('application_versions')->sole();
        $snapshot = json_decode((string) $after->data_snapshot, true);
        $expected = $legacy;
        unset($expected['nomination_details']['implementation'], $expected['nomination_details']['locations'], $expected['nomination_details']['funding'], $expected['nomination_details']['partnerships']);
        $this->assertSame($expected, $snapshot, 'only the retired keys go; the rest is unchanged');
        $this->assertSame(CanonicalJson::hash($snapshot), $after->content_hash);
        $this->assertNotSame($oldHash, $after->content_hash);
        foreach (['id', 'application_id', 'version_number', 'submission_type', 'submitted_by', 'submitted_at', 'form_snapshot', 'created_at'] as $column) {
            $this->assertEquals($version->{$column}, $after->{$column}, $column);
        }
        foreach (['مؤسسة الروّاد', '1500.50', 'العاديات', 'القيمرية', '2021-09-15'] as $value) {
            $this->assertStringNotContainsString($value, (string) $after->data_snapshot);
            $this->assertSame(0, DB::table('audit_logs')->where('after_data', 'like', "%{$value}%")->count(), "no copy of {$value} is kept");
        }
        $record = collect(DB::table('audit_logs')->where('action', 'nomination_fields.purged')->pluck('after_data'))->map(fn ($data) => json_decode((string) $data, true));
        $this->assertSame([['application_version_id' => (int) $version->id, 'old_hash' => $oldHash, 'new_hash' => $after->content_hash, 'old_hash_verified' => true]], $record->first(fn (array $data): bool => is_array($data['versions'] ?? null))['versions']);

        // Audit events stay, without the retired key; other events are untouched.
        foreach (DB::table('audit_logs')->where('action', 'nomination_details.confirmed')->orderBy('id')->get() as $index => $row) {
            $this->assertSame(json_decode((string) $confirmed[$index]->after_data, true), json_decode((string) $row->after_data, true), 'the event is kept, only funding_type goes');
        }
        $this->assertSame(['implementation_country' => 'SY', 'funding_type' => 'kept'], json_decode((string) DB::table('audit_logs')->where('id', $unrelated)->value('after_data'), true));
        $this->assertEquals($kept, [DB::table('applications')->where('id', $submitted['id'])->first(['title', 'purpose_and_mission', 'objectives', 'documented_impact', 'status', 'review_status', 'reference_number', 'first_submitted_at']),
            DB::table('judging_assignments')->orderBy('id')->get(), DB::table('file_links')->orderBy('id')->get(), DB::table('candidate_profiles')->get(), DB::table('applicant_individual_details')->get()]);

        // Idempotent; the admin and judge views show what remains.
        $this->assertSame(0, array_sum(array_intersect_key($migration->purge(), array_flip(['applications', 'locations', 'partnerships', 'versions', 'audit_rows']))));
        $this->read("/api/admin/applications/{$submitted['id']}")->assertOk()->assertJsonMissingPath('data.nomination_details.funding')
            ->assertJsonPath('data.summary.content_hash', $after->content_hash);

        // A rollback that would need the removed values is refused.
        try {
            $migration->down();
            $this->fail('the rollback should be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('cannot recreate', $refusal->getMessage());
        }
    }

    public function test_an_interrupted_purge_keeps_its_evidence_and_still_refuses_rollback_after_resuming(): void
    {
        $submitted = $this->submitApplication($this->candidate);
        DB::table('application_locations')->insert(['application_id' => $submitted['id'], 'country_code' => 'SY', 'governorate' => 'SY-DI', 'locality' => 'دمشق القديمة']);
        foreach ([1, 2] as $position) {
            DB::table('application_partnerships')->insert(['application_id' => $submitted['id'], 'position' => $position, 'partner_name' => "جهة {$position}"]);
        }
        $version = DB::table('application_versions')->sole();
        $legacy = json_decode((string) $version->data_snapshot, true);
        $legacy['nomination_details']['funding'] = ['type' => 'self_funded', 'funder_name' => null, 'amount_usd' => '10.00'];
        DB::table('application_versions')->where('id', $version->id)->update(['data_snapshot' => json_encode($legacy, JSON_UNESCAPED_UNICODE), 'content_hash' => CanonicalJson::hash($legacy)]);
        $migration = require database_path('migrations/2026_10_29_900003_purge_retired_nomination_fields.php');

        // The run stops while deleting partnerships, after the places batch committed.
        $interrupt = true;
        DB::listen(function ($query) use (&$interrupt): void {
            if ($interrupt && str_starts_with(strtolower(ltrim($query->sql)), 'delete from') && str_contains($query->sql, 'application_partnerships')) {
                throw new RuntimeException('simulated interruption');
            }
        });
        try {
            $migration->purge();
            $this->fail('the simulated interruption should surface');
        } catch (RuntimeException $interruption) {
            $this->assertSame('simulated interruption', $interruption->getMessage());
        }

        // The committed batch left its evidence; the interrupted one left neither change nor evidence.
        $this->assertSame([0, 2], [DB::table('application_locations')->count(), DB::table('application_partnerships')->count()]);
        $this->assertSame(['locations' => 1, 'partnerships' => 0, 'versions' => 0], array_intersect_key($migration->evidence(), array_flip(['locations', 'partnerships', 'versions'])));
        $this->assertRollbackRefused($migration);

        // Resumed: the rest is removed, the first run's evidence stays, nothing is counted twice.
        $interrupt = false;
        $resumed = $migration->purge();
        $this->assertSame([0, 2, 1], [$resumed['locations'], $resumed['partnerships'], $resumed['versions']], 'this run removed only what was left');
        $this->assertSame(['locations' => 1, 'partnerships' => 2, 'versions' => 1], array_intersect_key($migration->evidence(), array_flip(['locations', 'partnerships', 'versions'])));
        $this->assertSame([0, 0], [DB::table('application_locations')->count(), DB::table('application_partnerships')->count()]);
        $this->assertSame(0, array_sum(array_intersect_key($migration->purge(), array_flip(['locations', 'partnerships', 'versions', 'audit_rows']))), 'a third run finds nothing');
        $this->assertSame(['locations' => 1, 'partnerships' => 2, 'versions' => 1], array_intersect_key($migration->evidence(), array_flip(['locations', 'partnerships', 'versions'])));
        $this->assertRollbackRefused($migration);
        foreach (DB::table('audit_logs')->where('action', 'nomination_fields.purged')->pluck('after_data') as $data) {
            $this->assertStringNotContainsString('جهة', (string) $data, 'evidence never holds a removed value');
        }
    }

    public function test_rollback_refusal_reads_the_evidence_structurally_whatever_its_json_formatting(): void
    {
        $migration = require database_path('migrations/2026_10_29_900003_purge_retired_nomination_fields.php');
        $this->assertSame(0, array_sum($migration->evidence()), 'a fresh installation removed nothing, so it may roll back');
        $evidence = ['kind' => 'partnerships', 'removed' => 3, 'migration' => '2026_10_29_900003_purge_retired_nomination_fields'];

        foreach (['compact' => json_encode($evidence), 'formatted' => json_encode($evidence, JSON_PRETTY_PRINT), 'spaced' => '{"kind": "partnerships" , "removed" : 3, "migration": "2026_10_29_900003_purge_retired_nomination_fields"}'] as $label => $json) {
            $row = DB::table('audit_logs')->insertGetId(['action' => 'nomination_fields.purged', 'after_data' => $json, 'occurred_at' => now()->format('Y-m-d H:i:s.u')]);
            $this->assertSame(3, $migration->evidence()['partnerships'], $label);
            $this->assertRollbackRefused($migration);
            DB::table('audit_logs')->where('id', $row)->delete();
        }

        // Another migration's rows and a summary are not removal evidence.
        DB::table('audit_logs')->insert(['action' => 'nomination_fields.purged', 'after_data' => json_encode(['kind' => 'partnerships', 'removed' => 5, 'migration' => 'other']), 'occurred_at' => now()]);
        DB::table('audit_logs')->insert(['action' => 'nomination_fields.purged', 'after_data' => json_encode(['kind' => 'summary', 'partnerships' => 9, 'migration' => '2026_10_29_900003_purge_retired_nomination_fields']), 'occurred_at' => now()]);
        $this->assertSame(0, array_sum($migration->evidence()));
    }

    public function test_withdrawn_or_closed_applications_are_not_referred_and_an_undelivered_referral_records_nothing(): void
    {
        [, $committee] = $this->approvedCommittee('creativity', ['w1', 'w2', 'w3']);
        $withdrawn = $this->submitApplication($this->candidate);
        $closed = $this->submitApplication($this->person('omar@example.org'), ['title' => 'مغلق']);
        $ordinary = $this->submitApplication($this->person('rana@example.org'), ['title' => 'عادي']);
        DB::table('applications')->where('id', $withdrawn['id'])->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'withdrawal_reason' => 'طلب المترشح']);
        DB::table('applications')->where('id', $closed['id'])->update(['status' => 'closed']);
        $this->signInAdmin();

        foreach ([$withdrawn, $closed] as $application) {
            $before = DB::table('applications')->where('id', $application['id'])->first();
            $this->read("/api/admin/applications/{$application['id']}")->assertOk()
                ->assertJsonPath('data.review.status', 'pending')->assertJsonPath('data.review.target.refusal', 'not_eligible');
            $this->review($application['id'], 'refer', ['committee_id' => $committee])->assertConflict()
                ->assertJsonPath('code', 'not_eligible')->assertJsonPath('data.status', 'pending');
            $this->assertEquals($before, DB::table('applications')->where('id', $application['id'])->first(), 'state kept as it was');
        }
        $this->assertSame(3, $this->read("/api/admin/applications?cycle={$this->cycleId}")->json('meta.total'), 'still visible');

        // Provisioning that delivers nothing: the referral rolls back with its event.
        $this->app->instance(Assignments::class, new class extends Assignments
        {
            public function provisionReferral(int $applicationId, int $assignedBy): void {}
        });
        $this->review($ordinary['id'], 'refer', ['committee_id' => $committee])->assertConflict()->assertJsonPath('code', 'referral_not_delivered')->assertJsonPath('data.status', 'pending');
        $this->assertSame([0, 0], [DB::table('judging_assignments')->count(), DB::table('audit_logs')->where('action', 'application.referred')->count()]);
        $this->assertSame(['pending', 'pending', 'pending'], DB::table('applications')->orderBy('id')->pluck('review_status')->all());

        // The ordinary referral, once provisioning works again: delivered, then idempotent.
        $this->app->forgetInstance(Assignments::class);
        $this->review($ordinary['id'], 'refer', ['committee_id' => $committee])->assertOk()->assertJsonPath('code', 'referred');
        $this->review($ordinary['id'], 'refer', ['committee_id' => $committee])->assertOk()->assertJsonPath('code', 'unchanged');
        $this->assertSame(3, DB::table('judging_assignments')->where('application_id', $ordinary['id'])->where('committee_id', $committee)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'application.referred')->count());
    }

    private function assertRollbackRefused(object $migration): void
    {
        try {
            $migration->down();
            $this->fail('the rollback should be refused');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('cannot recreate', $refusal->getMessage());
        }
    }

    public function test_the_application_card_uses_the_submitted_version_and_is_audited(): void
    {
        $submitted = $this->submitApplication($this->candidate, ['title' => 'العنوان كما أُرسل']);
        DB::table('applications')->where('id', $submitted['id'])->update(['title' => 'عنوان معدَّل بعد الإرسال']);
        $this->signInAdmin();

        $card = json_encode(ApplicationCard::build(app(SubmittedApplications::class)->show($submitted['id'])), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('العنوان كما أُرسل', $card);
        $this->assertStringNotContainsString('عنوان معدَّل', $card);
        $this->assertStringContainsString('حالة التحكيم وقت التصدير', $card);
        $this->assertStringContainsString('بانتظار الفحص', $card);
        foreach (['applications/', '/api/', 'storage', 'sha256'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $card, $hidden);
        }

        $response = $this->read("/api/admin/applications/{$submitted['id']}/card")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('attachment; filename="application-card-', (string) $response->headers->get('Content-Disposition'));
        $event = DB::table('audit_logs')->where('action', 'report.exported')->sole();
        $this->assertSame(['application', $submitted['id'], $this->cycleId], [$event->subject_type, (int) $event->subject_id, (int) $event->cycle_id]);

        DB::table('applications')->where('id', $submitted['id'])->update(['status' => 'draft']);
        $this->read("/api/admin/applications/{$submitted['id']}/card")->assertNotFound();
        $this->signIn();
        $this->read("/api/admin/applications/{$submitted['id']}/card")->assertForbidden();
    }

    public function test_every_recorded_action_has_an_arabic_label_in_the_activity_log(): void
    {
        $recorded = [];
        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all("/AuditLog::record\(\s*'([a-z_.]+)'/", $file->getContents(), $matches);
            array_push($recorded, ...$matches[1]);
        }

        $this->assertNotEmpty($recorded);
        $this->assertSame([], array_values(array_diff(array_unique($recorded), array_keys(AuditTrail::ACTIONS))));
    }

    public function test_the_activity_log_attributes_events_once_to_their_real_cycle_with_safe_details(): void
    {
        $this->signInAdmin();
        $this->createCommittee('creativity')->assertCreated();
        $this->createCommittee('creativity')->assertConflict();
        $created = DB::table('audit_logs')->where('action', 'committee.created')->sole();
        $this->assertSame($this->cycleId, (int) $created->cycle_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $created->request_id);
        $login = DB::table('audit_logs')->where('action', 'auth.login')->where('actor_user_id', $this->admin->id)->first();
        $this->assertNotNull($login);
        $this->assertNull($login->cycle_id, 'an account event has no cycle');

        // A stored payload with private fields: shown only through the allowlist, never changed.
        $payload = json_encode(['score' => '87.5', 'national_id_number' => '04019876543', 'notes' => 'سري', 'judging_assignment_id' => 3]);
        $synthetic = DB::table('audit_logs')->insertGetId(['action' => 'evaluation.finalized', 'subject_type' => 'evaluation', 'subject_id' => 1,
            'after_data' => $payload, 'occurred_at' => now()->subYear()->format('Y-m-d H:i:s.u')]);

        $all = $this->read('/api/admin/log/audit?per_page=100')->assertOk();
        $actions = array_column($all->json('data'), 'action');
        foreach (['committee.created', 'auth.login', 'evaluation.finalized', 'permission.provisioned'] as $action) {
            $this->assertContains($action, $actions, $action);
        }
        $body = (string) $all->getContent();
        foreach (['87.5', '04019876543', 'سري'] as $private) {
            $this->assertStringNotContainsString($private, $body, $private);
        }
        $this->assertSame($payload, DB::table('audit_logs')->where('id', $synthetic)->value('after_data'), 'stored rows are not rewritten');
        $occurred = array_column($all->json('data'), 'occurred_at');
        $sorted = $occurred;
        rsort($sorted);
        $this->assertSame($sorted, $occurred, 'newest first');

        $inCycle = $this->read("/api/admin/log/audit?cycle={$this->cycleId}&per_page=100")->assertOk()->json('data');
        $this->assertContains('committee.created', array_column($inCycle, 'action'));
        $this->assertNotContains('auth.login', array_column($inCycle, 'action'));
        $this->assertSame([$this->cycleId], array_values(array_unique(array_column(array_column($inCycle, 'cycle'), 'id'))));
        $this->assertNotContains('committee.created', array_column($this->read('/api/admin/log/audit?cycle=none&per_page=100')->json('data'), 'action'));
        $this->assertSame(['committee.created'], array_column($this->read("/api/admin/log/audit?request_id={$created->request_id}")->json('data'), 'action'));
        $this->assertContains('permission.provisioned', array_column($this->read('/api/admin/log/audit?actor=system&per_page=100')->json('data'), 'action'));

        $export = $this->read("/api/admin/exports/audit?format=xlsx&cycle={$this->cycleId}")->assertOk();
        $this->assertSame(count($inCycle), $this->sheetScope($export->baseResponse->getFile()->getPathname())['عدد الصفوف']);
    }

    // ---------- helpers ----------

    /**
     * An approved judging committee of existing accounts: [judges, committee id].
     *
     * @param  list<string>  $names
     * @return array{0: list<User>, 1: int}
     */
    private function approvedCommittee(string $domain, array $names): array
    {
        $this->signInAdmin();
        $this->adminPost('/committees', ['domain' => $domain, 'name' => "لجنة {$domain}"])->assertCreated();
        $judges = [];
        foreach ($names as $name) {
            $judges[] = $judge = User::factory()->create(['email' => "{$name}@judges.example.org", 'name' => "حكَم {$name}"]);
            $this->appoint($domain, $judge->email, existing: true)->assertCreated();
        }
        $this->approve($domain)->assertOk();

        return [$judges, (int) DB::table('committees')->where('name_ar', "لجنة {$domain}")->value('id')];
    }

    /**
     * «إرسال إلى اللجنة» (refer) or «استبعاد وأرشفة» (exclude), from the signed-in administrator's page.
     *
     * @param  array<string, mixed>  $payload
     */
    private function review(int $applicationId, string $decision, array $payload): TestResponse
    {
        return $this->browser('POST', "/api/admin/applications/{$applicationId}/review/{$decision}", $payload + ['account_context' => AccountContext::for($this->admin)]);
    }

    /**
     * Refers to the committee the page shows.
     */
    private function refer(int $applicationId): TestResponse
    {
        return $this->review($applicationId, 'refer', ['committee_id' => (int) $this->read("/api/admin/applications/{$applicationId}")->json('data.review.target.committee.id')]);
    }

    /**
     * @return list<list<mixed>>
     */
    private function sheetRows(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetScope(string $path): array
    {
        $rows = $this->sheetRows($path);
        @unlink($path);

        return array_column(array_filter($rows, fn (array $row) => count($row) === 2), 1, 0);
    }

    private function person(string $email): User
    {
        return User::factory()->candidate()->create(['email' => $email]);
    }

    private function signInAdmin(): void
    {
        if (! app(AdminAccess::class)->isAdmin($this->admin)) {
            $this->artisan('admin:grant-access', ['--email' => $this->admin->email])->assertSuccessful();
        }
        $this->signIn($this->admin->email);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function adminPost(string $path, array $payload, ?int $cycle = null): TestResponse
    {
        $this->travel(2)->seconds();

        return $this->browser('POST', '/api/admin/cycles/'.($cycle ?? $this->cycleId).$path, $payload + [
            'account_context' => $this->signedIn ? AccountContext::for($this->signedIn) : null,
        ]);
    }

    private function createCommittee(string $domain): TestResponse
    {
        return $this->adminPost('/committees', ['domain' => $domain, 'name' => "لجنة تحكيم {$domain}"]);
    }

    /**
     * As the dialog does: an existing account is looked up first and
     * confirmed with the binding that lookup returned.
     *
     * @param  array<string, mixed>  $extra
     */
    private function appoint(string $domain, string $email, bool $existing = false, array $extra = [], ?int $cycle = null): TestResponse
    {
        if ($existing && ! array_key_exists('account_ref', $extra)) {
            $extra['account_ref'] = $this->adminPost('/judges/lookup', ['email' => $email], $cycle)->json('data.account.account_ref') ?? str_repeat('0', 64);
        }

        return $this->adminPost('/judges', $extra + ['domain' => $domain, 'email' => $email, 'name' => 'حكَم '.Str::before($email, '@'), 'existing' => $existing], $cycle);
    }

    private function approve(string $domain): TestResponse
    {
        return $this->adminPost("/committees/{$domain}/approve", []);
    }

    /**
     * Stages 1–3 confirmed through the candidate API (one PDF), ready to
     * submit; returns the documentation journey key.
     *
     * @param  array<string, mixed>  $details
     */
    private function readyToSubmit(User $user, array $details = [], bool $confirmStage3 = true): string
    {
        $this->completeStage1($user);
        $applicantId = (int) DB::table('candidate_profiles')->where('user_id', $user->id)->value('applicant_id');
        (new ApplicantIndividualDetail)->forceFill([
            'applicant_id' => $applicantId, 'first_name' => 'ليلى', 'last_name' => 'حسن', 'birth_date' => '1990-04-02',
            'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => self::NATIONAL_ID,
        ])->save();
        $this->signIn($user->email);
        $this->confirm($details + $this->payload(), $this->journeyKey())->assertCreated();
        $docKey = (string) $this->read('/api/candidate/documentation')->assertOk()->json('journey_key');

        $path = tempnam(sys_get_temp_dir(), 'sa-admin-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".str_repeat(' ', 512));
        $this->travel(3)->seconds();
        $this->browser('POST', '/api/candidate/documentation/files', [
            'kind' => 'explanatory', 'operation_id' => (string) Str::uuid(),
            'account_context' => AccountContext::for($user), 'journey_key' => $docKey,
        ], files: ['file' => new UploadedFile($path, 'شرح العمل.pdf', null, null, true)])->assertCreated();
        if ($confirmStage3) {
            $this->send('/api/candidate/documentation/confirm', ['revision' => 1], $docKey)->assertOk();
        }

        return $docKey;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{id: int, reference_number: string}
     */
    private function submitApplication(User $user, array $details = []): array
    {
        $docKey = $this->readyToSubmit($user, $details);
        $receipt = $this->send('/api/candidate/submission', ['reviewed_revision' => 1], $docKey)->assertCreated()->json('data');

        return ['id' => (int) DB::table('applications')->where('created_by', $user->id)->value('id'), 'reference_number' => $receipt['reference_number']];
    }
}
