<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Mime\Email;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->startCsrfSession();
    }

    public function test_the_reset_request_answers_the_same_for_any_address(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        User::factory()->disabled()->create(['email' => 'disabled@example.org']);

        foreach (['reader@example.org', 'nobody@example.org', 'disabled@example.org'] as $email) {
            $this->browser('POST', '/api/auth/forgot-password', ['email' => $email])
                ->assertStatus(202)
                ->assertExactJson(['message' => PasswordResetLinkController::SENT_MESSAGE]);
        }

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails, 'only the active account gets a link');
        $this->assertSame('reader@example.org', $emails[0]->getTo()[0]->getAddress());
    }

    public function test_a_reset_link_sets_a_new_password(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.org']);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'Reader@example.org'])->assertStatus(202);

        $link = $this->linkIn($this->sentEmails()[0]);
        $this->assertStringStartsWith(url('/reset-password').'?token=', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame('reader@example.org', $query['email']);

        $this->browser('POST', '/api/auth/reset-password', [
            'token' => $query['token'], 'email' => $query['email'], 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-9', $user->fresh()->password));
        $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'password'])->assertUnprocessable();
        $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'new-secret-9'])->assertOk();

        // A used token cannot be replayed.
        $this->browser('POST', '/api/auth/reset-password', [
            'token' => $query['token'], 'email' => $query['email'], 'password' => 'other-secret-9', 'password_confirmation' => 'other-secret-9',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_invalid_tokens_and_unknown_addresses_get_the_same_error(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);

        foreach (['reader@example.org', 'nobody@example.org'] as $email) {
            $this->browser('POST', '/api/auth/reset-password', [
                'token' => 'not-a-real-token', 'email' => $email, 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9',
            ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.token.0', 'رابط تعيين كلمة المرور غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.');
        }
    }

    public function test_a_google_only_account_can_set_a_password_by_reset(): void
    {
        $user = User::factory()->passwordless()->create(['email' => 'google.only@example.org']);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'google.only@example.org'])->assertStatus(202);
        parse_str((string) parse_url($this->linkIn($this->sentEmails()[0]), PHP_URL_QUERY), $query);

        $this->browser('POST', '/api/auth/reset-password', [
            'token' => $query['token'], 'email' => $query['email'], 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9',
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasPassword());
    }

    public function test_reset_requests_are_rate_limited(): void
    {
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'a@example.org'])->assertStatus(202);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'a@example.org'])->assertStatus(202);
        $this->browser('POST', '/api/auth/forgot-password', ['email' => 'a@example.org'])->assertStatus(429);
    }

    /**
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return array_values(array_map(
            fn ($message) => $message->getOriginalMessage(),
            iterator_to_array(app('mailer')->getSymfonyTransport()->messages()),
        ));
    }

    private function linkIn(Email $email): string
    {
        preg_match('/^http\S+$/m', (string) $email->getTextBody(), $matches);

        return $matches[0];
    }
}
