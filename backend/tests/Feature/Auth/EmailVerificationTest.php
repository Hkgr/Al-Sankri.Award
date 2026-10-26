<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * The verification email is sent through the real (array) mail transport,
 * and its link is followed as a browser would.
 */
class EmailVerificationTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->startCsrfSession();
    }

    public function test_registration_sends_an_arabic_verification_email_whose_link_verifies(): void
    {
        $this->register('layla@example.org');

        $email = $this->lastEmail();
        $this->assertSame('layla@example.org', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('تأكيد بريدك الإلكتروني', (string) $email->getSubject());
        $this->assertStringContainsString('dir="rtl"', (string) $email->getHtmlBody());
        $link = $this->linkIn($email);
        $this->assertStringStartsWith(url('/email/verify/'), $link);

        $this->navigate($link)->assertRedirect('/verify-email?status=verified');
        $this->assertTrue(User::query()->sole()->hasVerifiedEmail());
        $this->browser('GET', '/api/user')->assertJsonPath('data.email_verified', true);

        $this->navigate($link)->assertRedirect('/verify-email?status=already');
    }

    public function test_a_tampered_or_expired_link_is_refused(): void
    {
        $this->register('layla@example.org');
        $link = $this->linkIn($this->lastEmail());

        $this->navigate(str_replace('signature=', 'signature=0', $link))->assertRedirect('/verify-email?status=invalid');

        $this->travel(61)->minutes();
        $this->navigate($link)->assertRedirect('/verify-email?status=invalid');
        $this->assertFalse(User::query()->sole()->hasVerifiedEmail());
    }

    public function test_a_link_for_another_account_is_refused(): void
    {
        $other = User::factory()->unverified()->create();
        $foreignLink = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $other->getKey(), 'hash' => sha1($other->email)], absolute: false);

        $this->register('layla@example.org');

        $this->navigate($foreignLink)->assertRedirect('/verify-email?status=mismatch');
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_a_signed_out_visitor_signs_in_and_returns_to_the_link(): void
    {
        $this->register('layla@example.org');
        $link = $this->linkIn($this->lastEmail());
        $this->browser('POST', '/api/auth/logout')->assertNoContent();

        $this->navigate($link)->assertRedirect('/login');

        $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
        $this->browser('POST', '/api/auth/login', ['email' => 'layla@example.org', 'password' => 'secret-pass-1'])
            ->assertOk()
            ->assertJsonPath('redirect', $path);
        $this->navigate($link)->assertRedirect('/verify-email?status=verified');
    }

    public function test_the_verification_email_can_be_resent_with_a_limit(): void
    {
        $this->register('layla@example.org');

        $this->browser('POST', '/api/email/verification-notification')->assertStatus(202)->assertJsonPath('status', 'sent');
        $this->assertCount(2, $this->sentEmails());

        $this->browser('POST', '/api/email/verification-notification')->assertStatus(202);
        $this->browser('POST', '/api/email/verification-notification')->assertStatus(202);
        $this->browser('POST', '/api/email/verification-notification')->assertStatus(429);
    }

    public function test_verified_accounts_are_not_sent_another_email(): void
    {
        User::factory()->create(['email' => 'reader@example.org']);
        $this->browser('POST', '/api/auth/login', ['email' => 'reader@example.org', 'password' => 'password'])->assertOk();

        $this->browser('POST', '/api/email/verification-notification')->assertOk()->assertJsonPath('status', 'already_verified');
        $this->assertCount(0, $this->sentEmails());
    }

    private function register(string $email): void
    {
        $this->browser('POST', '/api/auth/register', [
            'name' => 'ليلى', 'email' => $email, 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertCreated();
    }

    /**
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return array_map(
            fn ($message) => $message->getOriginalMessage(),
            iterator_to_array(app('mailer')->getSymfonyTransport()->messages()),
        );
    }

    private function lastEmail(): Email
    {
        $emails = $this->sentEmails();
        $this->assertNotEmpty($emails, 'No email was sent.');

        return end($emails);
    }

    private function linkIn(Email $email): string
    {
        preg_match('/^http\S+$/m', (string) $email->getTextBody(), $matches);
        $this->assertNotEmpty($matches, 'No link in the email.');

        return $matches[0];
    }
}
