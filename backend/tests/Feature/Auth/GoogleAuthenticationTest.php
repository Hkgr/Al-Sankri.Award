<?php

namespace Tests\Feature\Auth;

use App\Auth\CandidateRole;
use App\Auth\GoogleAccounts;
use App\Auth\GoogleIdentity;
use App\Models\User;
use App\Notifications\ConfirmGoogleSignUp;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mime\Email;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * Google sign-in, MOCKED at the HTTP boundary only: Socialite's real Google
 * provider runs, including the session state check and PKCE, but Google's
 * token and userinfo endpoints answer from a queue instead of the network.
 * These tests do not prove a real Google authorization; see
 * docs/platform/authentication.md for that manual check.
 */
class GoogleAuthenticationTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    private MockHandler $google;

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $googleRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->google = new MockHandler;
        $stack = HandlerStack::create($this->google);
        $stack->push(Middleware::history($this->googleRequests));

        config(['services.google' => [
            'enabled' => true,
            'client_id' => 'test-client-id.apps.googleusercontent.com',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/auth/google/callback',
            'guzzle' => ['handler' => $stack],
        ]]);
    }

    public function test_the_interface_only_learns_whether_google_is_available(): void
    {
        $this->browser('GET', '/api/auth/options')->assertExactJson(['google' => ['available' => true]]);

        config(['services.google.client_secret' => null]);
        $this->browser('GET', '/api/auth/options')->assertExactJson(['google' => ['available' => false]]);

        config(['services.google.client_secret' => 'test-client-secret', 'services.google.enabled' => false]);
        $this->browser('GET', '/api/auth/options')->assertExactJson(['google' => ['available' => false]]);
        $this->navigate('/auth/google/redirect')->assertRedirect('/login?google=unavailable');
        $this->navigate('/auth/google/callback', ['code' => 'x', 'state' => 'y'])->assertRedirect('/login?google=unavailable');
    }

    public function test_the_redirect_requests_basic_scopes_with_state_and_pkce(): void
    {
        $query = $this->startGoogle();

        $this->assertSame('test-client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('http://localhost/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertArrayNotHasKey('access_type', $query, 'no offline access');
        $this->assertArrayNotHasKey('include_granted_scopes', $query);
    }

    public function test_a_new_gmail_user_gets_a_verified_passwordless_account(): void
    {
        $state = $this->startGoogle(['redirect' => '/account'])['state'];
        $this->queueGoogleUser(['sub' => '1098765432101234567890', 'email' => 'Layla@gmail.com', 'email_verified' => true, 'name' => 'ليلى حسن']);

        $this->returnFromGoogle($state)->assertRedirect('/account');

        $user = User::query()->sole();
        $this->assertSame('layla@gmail.com', $user->email);
        $this->assertSame('ليلى حسن', $user->name);
        $this->assertNull($user->password, 'no dummy password');
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('1098765432101234567890', DB::table('oauth_identities')->where('user_id', $user->id)->value('provider_subject'));
        $this->assertSame(['id', 'user_id', 'provider', 'provider_subject', 'linked_at', 'last_login_at', 'created_at', 'updated_at'], Schema::getColumnListing('oauth_identities'), 'no tokens are stored');

        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.google_linked', true)->assertJsonPath('data.has_password', false);

        // The authorization-code exchange carried the PKCE verifier.
        parse_str((string) $this->googleRequests[0]['request']->getBody(), $tokenRequest);
        $this->assertSame('authorization_code', $tokenRequest['grant_type']);
        $this->assertSame('test-auth-code', $tokenRequest['code']);
        $this->assertNotEmpty($tokenRequest['code_verifier']);
    }

    public function test_only_authoritative_google_emails_create_an_account_at_once(): void
    {
        $cases = [
            'gmail' => [['email' => 'layla@gmail.com', 'email_verified' => true], true],
            'workspace (hd) address' => [['email' => 'staff@example.org', 'email_verified' => true, 'hd' => 'example.org'], true],
            'other verified address without hd' => [['email' => 'person@outlook.example', 'email_verified' => true], false],
            'unverified gmail' => [['email' => 'unverified@gmail.com', 'email_verified' => false], false],
            'missing claim' => [['email' => 'noclaim@gmail.com'], false],
        ];

        Notification::fake();
        foreach ($cases as $label => [$claims, $authoritative]) {
            $this->cookieJar = [];
            $state = $this->startGoogle()['state'];
            $this->queueGoogleUser(['sub' => 'sub-'.md5($label), 'name' => 'Name'] + $claims);

            if ($authoritative) {
                $this->returnFromGoogle($state)->assertRedirect('/dashboard');
                $this->assertTrue(User::query()->where('email', $claims['email'])->sole()->hasVerifiedEmail(), $label);

                continue;
            }

            // Nothing is created for an unproven address: it must be confirmed first.
            $this->returnFromGoogle($state)->assertRedirect('/login?google=confirm_email');
            $this->assertFalse(User::query()->where('email', $claims['email'])->exists(), $label);
            $this->assertFalse(DB::table('oauth_identities')->where('provider_subject', 'sub-'.md5($label))->exists(), $label);
            $this->browser('GET', '/api/user')->assertUnauthorized();
            Notification::assertSentOnDemand(ConfirmGoogleSignUp::class, fn ($notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $claims['email']);
        }
    }

    public function test_an_unproven_google_email_is_confirmed_only_in_the_browser_that_started(): void
    {
        $this->useBrowser('google-holder');
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'outlook-sub', 'email' => 'person@outlook.example', 'email_verified' => true, 'name' => 'Person']);
        $this->returnFromGoogle($state)->assertRedirect('/login?google=confirm_email');
        $link = $this->linkIn($this->lastEmail('person@outlook.example'));
        $this->assertStringStartsWith(url('/auth/google/confirm').'?token=', $link);

        // The mailbox alone (another browser) creates nothing.
        $this->useBrowser('mailbox');
        $this->navigate($link)->assertRedirect('/login?google=confirm_invalid');
        $this->assertSame(0, User::query()->count());

        $this->useBrowser('google-holder');
        $this->navigate($link)->assertRedirect('/dashboard');
        $user = User::query()->sole();
        $this->assertSame('person@outlook.example', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame((int) $user->id, (int) DB::table('oauth_identities')->where('provider_subject', 'outlook-sub')->value('user_id'));
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.google_linked', true);

        // The link is single-use.
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $this->navigate($link)->assertRedirect('/login?google=confirm_invalid');
    }

    public function test_a_pending_google_sign_up_cannot_take_an_address_registered_meanwhile(): void
    {
        $this->useBrowser('google-holder');
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'late-sub', 'email' => 'person@outlook.example', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/login?google=confirm_email');
        $link = $this->linkIn($this->lastEmail('person@outlook.example'));

        $this->useBrowser('owner');
        $this->startCsrfSession();
        $this->register('person@outlook.example', 'owner-pass-1');

        $this->useBrowser('google-holder');
        $this->navigate($link)->assertRedirect('/login?google=account_exists');
        $this->assertSame(0, DB::table('oauth_identities')->count());
    }

    public function test_an_unrelated_google_identity_cannot_pre_hijack_an_unverified_account(): void
    {
        // The attacker registers the owner's address with their own password…
        $this->useBrowser('attacker');
        $this->startCsrfSession();
        $this->register('owner@example.org', 'attacker-pass-1');

        // …and tries to link their own Google identity before verification.
        $this->browser('POST', '/api/account/google/link', ['password' => 'attacker-pass-1'])->assertOk();
        $state = $this->googleQuery($this->navigate('/auth/google/redirect', ['intent' => 'link']))['state'];
        $this->queueGoogleUser(['sub' => 'attacker-sub', 'email' => 'attacker@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account?google=email_unverified');
        $this->assertSame(0, DB::table('oauth_identities')->count());

        // The real owner recovers the account through the emailed reset link.
        $this->useBrowser('owner');
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'owner@example.org'])->assertStatus(202);
        parse_str((string) parse_url($this->linkIn($this->lastEmail('owner@example.org')), PHP_URL_QUERY), $reset);
        $this->browser('POST', '/api/auth/reset-password', [
            'token' => $reset['token'], 'email' => 'owner@example.org', 'password' => 'owner-pass-1', 'password_confirmation' => 'owner-pass-1',
        ])->assertOk();
        $this->browser('POST', '/api/auth/login', ['email' => 'owner@example.org', 'password' => 'owner-pass-1'])->assertOk();

        // The attacker's session is gone, and their Google identity does not open the account.
        $this->useBrowser('attacker');
        $this->browser('GET', '/api/user')->assertUnauthorized();
        $this->cookieJar = [];
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'attacker-sub', 'email' => 'attacker@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/dashboard');
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.email', 'attacker@gmail.com');

        $owner = User::query()->where('email', 'owner@example.org')->sole();
        $this->assertFalse(DB::table('oauth_identities')->where('user_id', $owner->id)->exists());
    }

    public function test_a_pending_link_does_not_survive_a_password_reset(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        $this->useBrowser('first');
        $state = $this->startLink('reader@example.org');

        $this->useBrowser('owner');
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'reader@example.org'])->assertStatus(202);
        parse_str((string) parse_url($this->linkIn($this->lastEmail('reader@example.org')), PHP_URL_QUERY), $reset);
        $this->browser('POST', '/api/auth/reset-password', [
            'token' => $reset['token'], 'email' => 'reader@example.org', 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9',
        ])->assertOk();

        // Google returns to the session that started the link before the reset.
        $this->useBrowser('first');
        $this->queueGoogleUser(['sub' => 'stale-sub', 'email' => 'someone@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account?google=link_expired');
        $this->assertSame(0, DB::table('oauth_identities')->count());
    }

    public function test_a_pending_link_does_not_survive_disabling_the_account(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.org']);
        $state = $this->startLink('reader@example.org');

        $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now()])->save();

        $this->queueGoogleUser(['sub' => 'disabled-link-sub', 'email' => 'someone@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/login?account=disabled');
        $this->assertSame(0, DB::table('oauth_identities')->count());
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_a_pending_link_does_not_follow_a_change_of_account(): void
    {
        User::factory()->create(['email' => 'first@example.org']);
        User::factory()->create(['email' => 'second@example.org']);
        $state = $this->startLink('first@example.org');

        // Another account signs in on the same browser before Google returns.
        $this->browser('POST', '/api/auth/login', ['email' => 'second@example.org', 'password' => 'password'])->assertOk();

        $this->queueGoogleUser(['sub' => 'switched-sub', 'email' => 'someone@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account?google=link_expired');
        $this->assertSame(0, DB::table('oauth_identities')->count());
    }

    public function test_a_verified_account_can_link_a_google_identity_with_another_email(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.org']);
        $state = $this->startLink('reader@example.org');

        $this->queueGoogleUser(['sub' => 'other-email-sub', 'email' => 'reader.personal@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account?google=linked');

        $this->assertSame((int) $user->id, (int) DB::table('oauth_identities')->where('provider_subject', 'other-email-sub')->value('user_id'));
    }

    public function test_an_unverified_account_cannot_link_a_non_authoritative_google_identity(): void
    {
        User::factory()->unverified()->create(['email' => 'reader@outlook.example']);
        $state = $this->startLink('reader@outlook.example');

        // Same address, but Google is not authoritative for it.
        $this->queueGoogleUser(['sub' => 'outlook-link-sub', 'email' => 'reader@outlook.example', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account?google=email_unverified');
        $this->assertSame(0, DB::table('oauth_identities')->count());
    }

    public function test_a_returning_identity_signs_into_the_same_account(): void
    {
        $user = User::factory()->passwordless()->create(['email' => 'layla@gmail.com']);
        $this->linkIdentity($user, 'returning-sub');

        $state = $this->startGoogle()['state'];
        // Google now reports a different email: the subject, not the email, identifies the account.
        $this->queueGoogleUser(['sub' => 'returning-sub', 'email' => 'renamed@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account');

        $this->assertSame(1, User::query()->count());
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.email', 'layla@gmail.com');
        $this->assertNotNull(DB::table('oauth_identities')->where('provider_subject', 'returning-sub')->value('last_login_at'));
    }

    public function test_a_matching_email_is_never_linked_automatically(): void
    {
        $user = User::factory()->create(['email' => 'reader@gmail.com']);

        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'attacker-or-owner', 'email' => 'reader@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/login?google=account_exists');

        $this->assertSame(0, DB::table('oauth_identities')->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame($user->password, $user->fresh()->password);
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_a_disabled_account_cannot_sign_in_with_google(): void
    {
        $user = User::factory()->disabled()->passwordless()->create();
        $this->linkIdentity($user, 'disabled-sub');

        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'disabled-sub', 'email' => $user->email, 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/login?google=disabled');

        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_a_wrong_or_missing_state_is_rejected_before_contacting_google(): void
    {
        $this->startGoogle();
        $this->returnFromGoogle('forged-state-value')->assertRedirect('/login?google=invalid_state');

        // A callback without any sign-in started from this browser.
        $this->cookieJar = [];
        $this->returnFromGoogle('anything')->assertRedirect('/login?google=invalid_state');

        $this->assertCount(0, $this->googleRequests);
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_state_cannot_be_replayed(): void
    {
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'sub-1', 'email' => 'layla@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/dashboard');
        $this->browser('POST', '/api/auth/logout')->assertNoContent();

        $this->returnFromGoogle($state)->assertRedirect('/login?google=invalid_state');
    }

    public function test_cancelling_at_google_is_reported(): void
    {
        $state = $this->startGoogle()['state'];

        $this->navigate('/auth/google/callback', ['error' => 'access_denied', 'state' => $state])->assertRedirect('/login?google=cancelled');
        $this->assertCount(0, $this->googleRequests);
    }

    public function test_provider_failures_are_handled_without_logging_secrets(): void
    {
        Log::spy();
        $state = $this->startGoogle()['state'];
        $this->google->append(new Response(500, [], '{"error":"backend_error"}'));

        $this->returnFromGoogle($state)->assertRedirect('/login?google=failed');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $logged = json_encode($context);

            return $message === 'Google sign-in failed.'
                && $context['status'] === 500
                && ! str_contains($logged, 'test-auth-code')
                && ! str_contains($logged, 'test-client-secret');
        });
        $this->assertSame(0, User::query()->count());
    }

    public function test_an_owner_can_link_google_after_confirming_the_password(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'reader@gmail.com']);
        $this->signIn('reader@gmail.com');

        $this->browser('POST', '/api/account/google/link', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'كلمة المرور غير صحيحة.');
        $this->navigate('/auth/google/redirect', ['intent' => 'link'])->assertRedirect('/account?google=link_expired');

        $this->browser('POST', '/api/account/google/link', ['password' => 'password'])
            ->assertOk()
            ->assertExactJson(['redirect' => '/auth/google/redirect?intent=link']);
        $state = $this->googleQuery($this->navigate('/auth/google/redirect', ['intent' => 'link']))['state'];
        $this->queueGoogleUser(['sub' => 'owner-sub', 'email' => 'reader@gmail.com', 'email_verified' => true]);

        $this->returnFromGoogle($state)->assertRedirect('/account?google=linked');

        $this->assertSame((int) $user->id, (int) DB::table('oauth_identities')->where('provider_subject', 'owner-sub')->value('user_id'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail(), 'Google is authoritative for this Gmail address');
        $this->browser('GET', '/api/user')->assertJsonPath('data.google_linked', true);
        $this->browser('POST', '/api/account/google/link', ['password' => 'password'])->assertStatus(409);

        // The linked identity now signs in to this same account.
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'owner-sub', 'email' => 'reader@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account');
        $this->browser('GET', '/api/user')->assertJsonPath('data.email', 'reader@gmail.com');
    }

    public function test_an_identity_owned_by_another_account_cannot_be_linked(): void
    {
        $this->linkIdentity(User::factory()->passwordless()->create(), 'taken-sub');
        User::factory()->create(['email' => 'reader@example.org']);
        $this->signIn('reader@example.org');

        $this->browser('POST', '/api/account/google/link', ['password' => 'password'])->assertOk();
        $state = $this->googleQuery($this->navigate('/auth/google/redirect', ['intent' => 'link']))['state'];
        $this->queueGoogleUser(['sub' => 'taken-sub', 'email' => 'someone@gmail.com', 'email_verified' => true]);

        $this->returnFromGoogle($state)->assertRedirect('/account?google=owned_by_other');
        $this->assertSame(1, DB::table('oauth_identities')->count());
    }

    public function test_linking_is_unavailable_without_configuration(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        $this->signIn('reader@example.org');
        config(['services.google.enabled' => false]);

        $this->browser('POST', '/api/account/google/link', ['password' => 'password'])->assertStatus(503)->assertJsonPath('code', 'google_unavailable');
    }

    public function test_parallel_first_sign_ins_end_in_one_account(): void
    {
        // Another request created the account and identity after this one
        // looked them up but before it inserted its own.
        $winner = User::factory()->passwordless()->create(['email' => 'layla@gmail.com']);
        $this->linkIdentity($winner, 'race-sub');
        $accounts = new class extends GoogleAccounts
        {
            private bool $stale = true;

            protected function userForSubject(string $subject): ?User
            {
                if ($this->stale) {
                    $this->stale = false;

                    return null;
                }

                return parent::userForSubject($subject);
            }
        };
        User::query()->where('id', $winner->id)->update(['email' => 'layla.old@gmail.com']);

        [$outcome, $user] = $accounts->signIn(new GoogleIdentity('race-sub', 'layla@gmail.com', 'Layla', true, null));

        $this->assertSame(GoogleAccounts::SIGNED_IN, $outcome);
        $this->assertSame($winner->id, $user->id);
        $this->assertSame(1, User::query()->count(), 'the losing insert was rolled back');
        $this->assertSame(1, DB::table('oauth_identities')->count());
    }

    public function test_new_google_accounts_are_classified_as_candidates_once(): void
    {
        // An authoritative address creates the account at once.
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'gmail-sub', 'email' => 'layla@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/dashboard');
        $layla = User::query()->where('email', 'layla@gmail.com')->sole();
        $this->assertSame([CandidateRole::CODE], $this->roleCodes($layla));
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.is_candidate', true);

        // A returning sign-in adds no second grant.
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'gmail-sub', 'email' => 'layla@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/dashboard');
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $layla->id)->count());

        // A sign-up confirmed by email in the same browser is classified too.
        $this->useBrowser('outlook');
        $state = $this->startGoogle()['state'];
        $this->queueGoogleUser(['sub' => 'outlook-sub', 'email' => 'person@outlook.example', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/login?google=confirm_email');
        $this->navigate($this->linkIn($this->lastEmail('person@outlook.example')))->assertRedirect('/dashboard');
        $this->assertSame([CandidateRole::CODE], $this->roleCodes(User::query()->where('email', 'person@outlook.example')->sole()));
    }

    public function test_linking_google_does_not_change_the_accounts_roles(): void
    {
        $staffRole = DB::table('roles')->insertGetId(['code' => 'award_administrator', 'name_ar' => 'إدارة الجائزة', 'scope' => 'platform', 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::factory()->create(['email' => 'staff@example.org']);
        DB::table('user_roles')->insert(['user_id' => $staff->id, 'role_id' => $staffRole, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $grantsBefore = DB::table('user_roles')->orderBy('id')->get()->toArray();

        $state = $this->startLink('staff@example.org');
        $this->queueGoogleUser(['sub' => 'staff-sub', 'email' => 'staff.personal@gmail.com', 'email_verified' => true]);
        $this->returnFromGoogle($state)->assertRedirect('/account?google=linked');

        $this->assertEquals($grantsBefore, DB::table('user_roles')->orderBy('id')->get()->toArray());
        $this->assertSame(['award_administrator'], $this->roleCodes($staff));
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.is_candidate', false);
    }

    /**
     * @return list<string>
     */
    private function roleCodes(User $user): array
    {
        return DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)->whereNull('user_roles.revoked_at')
            ->orderBy('roles.code')->pluck('roles.code')->all();
    }

    /**
     * Starts a sign-in and returns Google's authorization URL parameters.
     *
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    private function startGoogle(array $query = []): array
    {
        return $this->googleQuery($this->navigate('/auth/google/redirect', $query));
    }

    /**
     * @return array<string, string>
     */
    private function googleQuery(TestResponse $response): array
    {
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function returnFromGoogle(string $state): TestResponse
    {
        return $this->navigate('/auth/google/callback', ['code' => 'test-auth-code', 'state' => $state]);
    }

    /**
     * @param  array<string, mixed>  $claims  Google userinfo claims.
     */
    private function queueGoogleUser(array $claims): void
    {
        $this->google->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'test-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer', 'scope' => 'openid email profile',
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($claims + ['name' => 'Google User'])),
        );
    }

    /**
     * Signs in and starts linking Google; returns Google's state value.
     */
    private function startLink(string $email): string
    {
        $this->signIn($email);
        $this->browser('POST', '/api/account/google/link', ['password' => 'password'])->assertOk();

        return $this->googleQuery($this->navigate('/auth/google/redirect', ['intent' => 'link']))['state'];
    }

    private function register(string $email, string $password): void
    {
        $this->browser('POST', '/api/auth/register', [
            'name' => 'Name', 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated();
    }

    /**
     * The latest email sent to the address through the real (array) transport.
     */
    private function lastEmail(string $to): Email
    {
        $emails = array_values(array_filter(
            array_map(fn ($message) => $message->getOriginalMessage(), iterator_to_array(app('mailer')->getSymfonyTransport()->messages())),
            fn (Email $email): bool => $email->getTo()[0]->getAddress() === $to,
        ));
        $this->assertNotEmpty($emails, "No email was sent to {$to}.");

        return end($emails);
    }

    private function linkIn(Email $email): string
    {
        preg_match('/^http\S+$/m', (string) $email->getTextBody(), $matches);
        $this->assertNotEmpty($matches, 'No link in the email.');

        return $matches[0];
    }

    private function linkIdentity(User $user, string $subject): void
    {
        DB::table('oauth_identities')->insert([
            'user_id' => $user->id, 'provider' => 'google', 'provider_subject' => $subject,
            'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function signIn(string $email): void
    {
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }
}
