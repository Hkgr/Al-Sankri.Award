<?php

namespace Tests\Feature\Admin;

use App\Admin\AdminAccess;
use App\Auth\CandidateRole;
use App\Candidates\AccountContext;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
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
        $this->read('/api/user')->assertJsonPath('data.admin_permissions', [AdminAccess::VIEW_APPLICATIONS, AdminAccess::MANAGE_COMMITTEES]);
        $this->navigate('/admin/applications')->assertOk();
        $this->navigate('/admin/applications/12')->assertOk();
        $this->navigate('/admin/settings')->assertNotFound();
        $this->navigate('/admin/applications/abc')->assertNotFound();
        $this->read('/api/admin/context')->assertOk()->assertJsonPath('data.default_cycle_id', $this->cycleId)
            ->assertJsonPath('data.cycles.0.domains.2.code', 'creativity');
        // Writes need the page's account snapshot.
        $this->browser('POST', "/api/admin/cycles/{$this->cycleId}/committees", ['domain' => 'creativity', 'name' => 'لجنة', 'account_context' => 'stale'])
            ->assertConflict()->assertJsonPath('code', 'account_mismatch');

        // A judge (an existing candidate appointed here): no administration, no candidate dashboard redirect.
        $this->createCommittee('creativity')->assertCreated();
        $this->appoint('creativity', $this->candidate->email, existing: true, extra: ['role' => 'award_admin', 'actor_id' => $this->operator->id])->assertCreated();
        $this->assertSame((int) $this->admin->id, (int) DB::table('audit_logs')->where('action', 'committee_member.added')->value('actor_user_id'));
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $this->candidate->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', '/account');
        $this->signedIn = $this->candidate;
        $this->read('/api/user')->assertJsonPath('data.is_judge', true)->assertJsonPath('data.is_candidate', true)->assertJsonPath('data.admin_permissions', []);
        $this->read('/api/admin/applications')->assertForbidden();
        $this->navigate('/admin')->assertForbidden();
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
        $this->assertSame('1500.50', $page->json('data.nomination_details.funding.amount_usd'));
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
        $this->assertTrue(app(CandidateRole::class)->isHeldBy($this->candidate), 'the candidate classification is kept');
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
        $application = $this->submitApplication($this->candidate);
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

        // Judging history makes ending a membership or a return to draft unsafe.
        $this->appoint('creativity', 'f@judges.example.org')->assertCreated();
        $this->approve('creativity')->assertOk();
        $committee = DB::table('committees')->sole();
        $member = DB::table('committee_members')->whereNull('left_at')->orderBy('id')->first();
        $rubric = DB::table('evaluation_rubrics')->insertGetId(['cycle_domain_id' => $committee->cycle_domain_id, 'version_number' => 1, 'name_ar' => 'معايير', 'status' => 'draft']);
        DB::table('judging_assignments')->insert([
            'application_version_id' => DB::table('applications')->where('id', $application['id'])->value('current_version_id'), 'application_id' => $application['id'],
            'cycle_domain_id' => $committee->cycle_domain_id, 'committee_id' => $committee->id, 'committee_member_id' => $member->id, 'rubric_id' => $rubric,
            'assigned_by' => $this->admin->id, 'assigned_at' => now(),
        ]);
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
        $this->send('/api/candidate/submission', ['reviewed_revision' => 1], $ready)->assertForbidden()->assertJsonPath('code', 'committee_member');
        $this->assertSame(1, DB::table('application_versions')->count());
        $this->signInAdmin();
        $this->adminPost("/committees/creativity/members/{$member}/end", [])->assertOk();
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

    // ---------- helpers ----------

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
    private function readyToSubmit(User $user, array $details = []): string
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
        $this->send('/api/candidate/documentation/confirm', ['revision' => 1], $docKey)->assertOk();

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
