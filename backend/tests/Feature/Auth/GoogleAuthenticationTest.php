<?php

namespace Tests\Feature\Auth;

use App\Auth\GoogleAccounts;
use App\Auth\GoogleIdentity;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
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

    public function test_google_emails_are_trusted_only_where_google_is_authoritative(): void
    {
        $cases = [
            'workspace (hd) address' => [['email' => 'staff@example.org', 'email_verified' => true, 'hd' => 'example.org'], true],
            'other verified address without hd' => [['email' => 'person@outlook.example', 'email_verified' => true], false],
            'unverified gmail' => [['email' => 'unverified@gmail.com', 'email_verified' => false], false],
            'missing claim' => [['email' => 'noclaim@gmail.com'], false],
        ];

        Notification::fake();
        foreach ($cases as $label => [$claims, $trusted]) {
            $this->cookieJar = [];
            $state = $this->startGoogle()['state'];
            $this->queueGoogleUser(['sub' => 'sub-'.md5($label), 'name' => 'Name'] + $claims);
            $this->returnFromGoogle($state)->assertRedirect('/account');

            $user = User::query()->where('email', $claims['email'])->sole();
            $this->assertSame($trusted, $user->hasVerifiedEmail(), $label);
            $trusted
                ? Notification::assertNotSentTo($user, VerifyEmail::class)
                : Notification::assertSentTo($user, VerifyEmail::class);
        }
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
        $this->returnFromGoogle($state)->assertRedirect('/account');
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
