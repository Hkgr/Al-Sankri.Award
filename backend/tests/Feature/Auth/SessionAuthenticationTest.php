<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * Email/password sign-in through the SPA session, with real cookies and
 * real CSRF verification.
 */
class SessionAuthenticationTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
    }

    public function test_state_changing_requests_need_the_csrf_token(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.org']);

        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $user->email, 'password' => 'password'], withCsrfHeader: false)
            ->assertStatus(419);
        $this->browser('GET', '/api/user')->assertUnauthorized();

        $this->browser('POST', '/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_login_regenerates_the_session_and_the_session_persists(): void
    {
        $user = User::factory()->create(['name' => 'سارة', 'email' => 'reader@example.org']);

        $this->startCsrfSession();
        $guestSession = $this->cookieJar[$this->sessionCookieName()];

        $this->browser('POST', '/api/auth/login', ['email' => 'Reader@Example.org ', 'password' => 'password'])
            ->assertOk()
            ->assertExactJson([
                'data' => ['name' => 'سارة', 'email' => 'reader@example.org', 'email_verified' => true, 'has_password' => true, 'google_linked' => false, 'is_candidate' => false],
                // Not a candidate (no role), so the account page.
                'redirect' => '/account',
            ]);

        $this->assertNotSame($guestSession, $this->cookieJar[$this->sessionCookieName()], 'session id must change on login');
        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.email', 'reader@example.org');
        $this->browser('GET', '/api/user')->assertOk();
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'password'])->assertOk();
        $signedInSession = $this->cookieJar[$this->sessionCookieName()];
        $signedInToken = $this->cookieJar['XSRF-TOKEN'];

        $this->browser('POST', '/api/auth/logout')->assertNoContent();

        $this->assertNotSame($signedInToken, $this->cookieJar['XSRF-TOKEN']);
        $this->browser('GET', '/api/user')->assertUnauthorized();

        // Replaying the old session cookie does not restore the login.
        $this->cookieJar[$this->sessionCookieName()] = $signedInSession;
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_protected_endpoints_reject_guests(): void
    {
        $this->browser('GET', '/api/user')->assertUnauthorized();
        $this->startCsrfSession();
        $this->browser('POST', '/api/email/verification-notification')->assertUnauthorized();
        $this->browser('POST', '/api/account/google/link', ['password' => 'x'])->assertUnauthorized();
    }

    public function test_wrong_credentials_get_one_message_whatever_the_reason(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        User::factory()->passwordless()->create(['email' => 'google.only@example.org']);
        $this->startCsrfSession();

        $responses = [
            $this->browser('POST', '/api/auth/login', ['email' => 'nobody@example.org', 'password' => 'password']),
            $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'wrong-password']),
            $this->browser('POST', '/api/auth/login', ['email' => 'google.only@example.org', 'password' => 'password']),
        ];

        foreach ($responses as $response) {
            $response->assertUnprocessable()->assertExactJson([
                'message' => LoginRequest::INVALID_CREDENTIALS,
                'errors' => ['email' => [LoginRequest::INVALID_CREDENTIALS]],
            ]);
        }
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_validation_errors_are_in_arabic(): void
    {
        $this->startCsrfSession();

        $this->browser('POST', '/api/auth/login', ['email' => 'not-an-email', 'password' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'أدخل البريد الإلكتروني صحيحاً، مثل name@example.com.')
            ->assertJsonPath('errors.password.0', 'أدخل كلمة المرور.');
    }

    public function test_repeated_failures_are_throttled_per_email_and_address(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        $this->startCsrfSession();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('errors.email.0', fn (string $message) => str_starts_with($message, 'محاولات دخول كثيرة.'));
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_the_api_is_rate_limited_per_address(): void
    {
        $this->startCsrfSession();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->browser('POST', '/api/auth/login', ['email' => "user{$attempt}@example.org", 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->browser('POST', '/api/auth/login', ['email' => 'another@example.org', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_disabled_accounts_cannot_sign_in(): void
    {
        User::factory()->disabled()->create(['email' => 'disabled@example.org']);
        $this->startCsrfSession();

        $this->browser('POST', '/api/auth/login', ['email' => 'disabled@example.org', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_an_account_disabled_during_a_session_is_signed_out(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.org']);
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'password'])->assertOk();

        $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now()])->save();

        $this->browser('GET', '/api/user')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_only_allowed_same_site_destinations_are_returned(): void
    {
        // A candidate: anything not allowed falls back to the dashboard.
        User::factory()->candidate()->create(['email' => 'reader@example.org']);
        $cases = [
            '/account' => '/account',
            '/dashboard' => '/dashboard',
            '/dashboard/basic-information' => '/dashboard/basic-information',
            '/email/verify/1/abc?expires=1&signature=x' => '/email/verify/1/abc?expires=1&signature=x',
            'https://evil.example/account' => '/dashboard',
            '//evil.example/dashboard' => '/dashboard',
            '/\\evil.example' => '/dashboard',
            'javascript:alert(1)' => '/dashboard',
            '/admin' => '/dashboard',
            '/dashboard/other' => '/dashboard',
            '/dashboardx' => '/dashboard',
        ];

        foreach ($cases as $requested => $expected) {
            // Stay under the per-IP login limit (10 per minute).
            $this->travel(7)->seconds();
            $this->cookieJar = [];
            $this->startCsrfSession();
            $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'password', 'redirect' => $requested])
                ->assertOk()
                ->assertJsonPath('redirect', $expected);
        }
    }
}
