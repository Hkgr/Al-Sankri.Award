<?php

namespace Tests\Feature\Auth;

use App\Auth\CandidateRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->startCsrfSession();
    }

    public function test_registration_creates_a_signed_in_candidate_account_only(): void
    {
        $this->browser('POST', '/api/auth/register', [
            'name' => '  ليلى   حسن ',
            'email' => ' Layla@Example.ORG',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            // Never accepted from the browser:
            'status' => 'disabled',
            'email_verified_at' => '2026-01-01 00:00:00',
            'role' => 'trustee',
            'roles' => ['administrator'],
            'is_candidate' => false,
        ])
            ->assertCreated()
            ->assertJson([
                'data' => ['name' => 'ليلى حسن', 'email' => 'layla@example.org', 'email_verified' => false, 'has_password' => true, 'google_linked' => false, 'is_candidate' => true],
                'redirect' => '/dashboard',
                'verification_email_sent' => true,
            ]);

        $user = User::query()->sole();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
        $this->assertNotSame('secret-pass-1', $user->getRawOriginal('password'));

        // Exactly one system grant of «مترشح», and nothing else: no applicant,
        // application, cycle role or committee membership.
        $grant = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->sole();
        $this->assertSame(
            [CandidateRole::CODE, 'platform', $user->id, null, null],
            [$grant->code, $grant->scope, (int) $grant->user_id, $grant->assigned_by, $grant->revoked_at],
        );
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user_role.granted')->where('subject_id', $user->id)->count());
        foreach (['cycle_user_roles', 'applicants', 'applicant_members', 'applications', 'committee_members', 'oauth_identities', 'candidate_profiles'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty");
        }

        $this->browser('GET', '/api/user')->assertOk()->assertJsonPath('data.email', 'layla@example.org');
    }

    public function test_registration_validates_in_arabic_and_keeps_input_out_of_the_database(): void
    {
        User::factory()->create(['email' => 'taken@example.org']);

        $this->browser('POST', '/api/auth/register', [
            'name' => '',
            'email' => 'TAKEN@example.org',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'أدخل الاسم الكامل.')
            ->assertJsonPath('errors.email.0', 'هذا البريد الإلكتروني مسجّل مسبقاً. سجّل الدخول، أو استعد كلمة المرور إن نسيتها.')
            ->assertJsonPath('errors.password', fn (array $errors) => in_array('تأكيد كلمة المرور غير مطابق.', $errors, true)
                && in_array('يجب أن تتكوّن كلمة المرور من 8 أحرف على الأقل.', $errors, true));

        $this->browser('POST', '/api/auth/register', [
            'name' => 'Name', 'email' => 'new@example.org', 'password' => 'onlyletters', 'password_confirmation' => 'onlyletters',
        ])->assertUnprocessable()->assertJsonPath('errors.password.0', 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.');

        $this->assertSame(1, User::query()->count());
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_a_mail_failure_does_not_lose_the_new_account(): void
    {
        // An SMTP server that refuses connections. The failure is reported to the log.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 9]);
        Mail::forgetMailers();
        Log::spy();

        $this->browser('POST', '/api/auth/register', [
            'name' => 'Name', 'email' => 'new@example.org', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertCreated()->assertJsonPath('verification_email_sent', false);

        $this->assertSame(1, User::query()->count());
        $this->browser('GET', '/api/user')->assertOk();
        Log::shouldHaveReceived('error')->once();
    }
}
