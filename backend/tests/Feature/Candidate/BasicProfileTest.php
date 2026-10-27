<?php

namespace Tests\Feature\Candidate;

use App\Candidates\AccountContext;
use App\Models\AccountPolicyVersion;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * Stage 1 of the candidate journey: the basic information, its validation,
 * policy acceptance, atomic confirmation and the permanent read-only lock.
 * Requests go through real cookies and CSRF (ActsAsSpaBrowser).
 */
class BasicProfileTest extends TestCase
{
    use ActsAsSpaBrowser, RefreshDatabase;

    private const PROFILE_TABLES = [
        'candidate_profiles', 'applicants', 'applicant_members', 'applicant_individual_details',
        'applicant_institution_details', 'applicant_social_links', 'account_policy_acceptances',
    ];

    private User $candidate;

    /** The account signed in through signIn(), whose form context requests carry. */
    private ?User $signedIn = null;

    /**
     * @var array<string, AccountPolicyVersion>
     */
    private array $policies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->candidate = User::factory()->candidate()->create(['name' => 'Layla Hassan', 'email' => 'layla@example.org']);
        foreach (AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE as $code) {
            $this->policies[$code] = AccountPolicyVersion::factory()->code($code)->published()->create();
        }
    }

    public function test_an_individual_confirms_the_basic_information_once_and_atomically(): void
    {
        $this->signIn();
        $this->browser('GET', '/api/candidate/overview')->assertOk()->assertJsonPath('data.basic_information.status', 'not_started');

        $response = $this->confirm($this->individualPayload())
            ->assertCreated()
            ->assertJsonPath('data.profile_type', 'individual')
            ->assertJsonPath('data.individual.first_name', 'Zoë')
            ->assertJsonPath('data.individual.middle_name', 'مُحَمَّد')
            ->assertJsonPath('data.individual.last_name', "O'Brien-Al Hasan")
            ->assertJsonPath('data.individual.birth_date', '1950-02-28')
            ->assertJsonPath('data.individual.nationality', ['code' => 'SY', 'name' => 'سوريا'])
            ->assertJsonPath('data.individual.residence_country.code', 'DE')
            ->assertJsonPath('data.individual.national_id_number', '00123/AB-9')
            ->assertJsonPath('data.individual.primary_phone', '+963944123456')
            ->assertJsonPath('data.individual.secondary_phone', '+4930123456')
            ->assertJsonPath('data.individual.contact_email', 'contact@example.net')
            ->assertJsonPath('data.social_links', [['platform' => 'LinkedIn', 'url' => 'https://www.linkedin.com/in/layla']])
            ->assertJsonCount(2, 'data.accepted_policies');
        $this->assertPrivate($response);

        $applicant = DB::table('applicants')->sole();
        $this->assertSame(['individual', 'Zoë مُحَمَّد O\'Brien-Al Hasan', $this->candidate->id, 'DE', 'Musterstraße 1, 10115 Berlin', '+963944123456', 'contact@example.net'], [
            $applicant->type, $applicant->name_ar, (int) $applicant->representative_user_id, $applicant->headquarters_country_code,
            $applicant->headquarters_address, $applicant->contact_phone, $applicant->contact_email,
        ]);
        $profile = DB::table('candidate_profiles')->sole();
        $this->assertSame(['individual', (int) $applicant->id], [$profile->profile_type, (int) $profile->applicant_id]);
        $this->assertNull($profile->representation_declared_at);

        // Exactly the published versions were accepted, by this account, at server time.
        $this->assertEqualsCanonicalizing(
            array_map(fn (AccountPolicyVersion $policy): int => $policy->id, array_values($this->policies)),
            DB::table('account_policy_acceptances')->where('user_id', $this->candidate->id)->pluck('policy_version_id')->map(fn ($id): int => (int) $id)->all(),
        );

        // The login account keeps its own name, email and verification.
        $this->assertSame(['Layla Hassan', 'layla@example.org'], [$this->candidate->fresh()->name, $this->candidate->fresh()->email]);
        $this->assertTrue($this->candidate->fresh()->hasVerifiedEmail());

        // The audit entry holds codes and ids, never the personal details.
        $audit = DB::table('audit_logs')->where('action', 'candidate_profile.completed')->sole();
        $this->assertSame([$this->candidate->id, 'candidate_profile', (int) $profile->id], [(int) $audit->actor_user_id, $audit->subject_type, (int) $audit->subject_id]);
        foreach (['00123', 'Zoë', 'Musterstraße', '1950', '+963', 'contact@example.net'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $audit->after_data);
        }

        $this->browser('GET', '/api/candidate/overview')->assertOk()
            ->assertJsonPath('data.basic_information.status', 'completed')
            ->assertJsonPath('data.basic_information.profile_type', 'individual');
    }

    public function test_the_identity_number_is_encrypted_at_rest_and_kept_out_of_generic_resources(): void
    {
        $this->signIn();
        $this->confirm($this->individualPayload())->assertCreated();

        $raw = DB::table('applicant_individual_details')->value('national_id_number');
        $this->assertStringNotContainsString('00123', $raw);
        $this->assertSame('00123/AB-9', Crypt::decryptString($raw));
        $this->assertSame('00123/AB-9', ApplicantIndividualDetail::query()->sole()->national_id_number);
        $this->assertArrayNotHasKey('national_id_number', ApplicantIndividualDetail::query()->sole()->toArray());

        foreach (['/api/user', '/api/candidate/overview'] as $uri) {
            $body = $this->browser('GET', $uri)->assertOk()->getContent();
            foreach (['00123', '1950-02-28', 'Musterstraße', '+963944123456', 'contact@example.net'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$uri} must not expose {$secret}");
            }
        }
    }

    public function test_an_institution_confirms_with_its_representative_as_the_account_holder(): void
    {
        $this->signIn();

        $this->confirm($this->institutionPayload())
            ->assertCreated()
            ->assertJsonPath('data.profile_type', 'institution')
            ->assertJsonPath('data.institution.name', 'جمعية التراث الحيّ')
            ->assertJsonPath('data.institution.license_number', '0042/2019')
            ->assertJsonPath('data.institution.representative_name', 'Layla Hassan')
            ->assertJsonPath('data.institution.phone', '+963112345678')
            ->assertJsonPath('data.institution.website', null)
            ->assertJsonPath('data.social_links', []);

        $applicant = DB::table('applicants')->sole();
        $this->assertSame(['organization', 'جمعية التراث الحيّ', '0042/2019', 'SY', 'info@heritage.example'], [
            $applicant->type, $applicant->name_ar, $applicant->registration_number, $applicant->headquarters_country_code, $applicant->contact_email,
        ]);
        $member = DB::table('applicant_members')->sole();
        $this->assertSame([(int) $applicant->id, $this->candidate->id, 'Layla Hassan', 'مديرة البرامج', 1], [
            (int) $member->applicant_id, (int) $member->user_id, $member->full_name, $member->role_title, (int) $member->is_primary_contact,
        ]);
        $this->assertNotNull(DB::table('candidate_profiles')->value('representation_declared_at'));
        $this->assertSame(0, DB::table('applicant_individual_details')->count());
        $this->assertSame(['Layla Hassan', 'layla@example.org'], [$this->candidate->fresh()->name, $this->candidate->fresh()->email]);
    }

    public function test_the_inactive_branch_and_missing_declarations_are_rejected(): void
    {
        $this->signIn();

        $mixed = $this->individualPayload();
        $mixed['institution'] = $this->institutionPayload()['institution'];
        $this->confirm($mixed)->assertUnprocessable()->assertJsonValidationErrors(['institution'])->assertJsonMissingValidationErrors(['individual']);

        $mixed = $this->institutionPayload();
        $mixed['individual'] = $this->individualPayload()['individual'];
        $this->confirm($mixed)->assertUnprocessable()->assertJsonValidationErrors(['individual']);

        $payload = $this->individualPayload();
        $payload['declarations']['representation'] = true;
        $this->confirm($payload)->assertUnprocessable()->assertJsonValidationErrors(['declarations.representation']);

        $payload = $this->institutionPayload();
        unset($payload['declarations']['representation']);
        $this->confirm($payload)->assertUnprocessable()->assertJsonValidationErrors(['declarations.representation']);

        foreach (['data_accuracy' => false, 'privacy' => null] as $field => $value) {
            $payload = $this->individualPayload();
            if ($field === 'privacy') {
                unset($payload['accepted_policies']['privacy_policy']);
            } else {
                $payload['declarations'][$field] = $value;
            }
            $this->confirm($payload)->assertUnprocessable();
        }

        $this->assertNothingStored();
    }

    public function test_fields_are_validated_with_arabic_messages(): void
    {
        $this->signIn();
        $cases = [
            'individual.birth_date' => ['2031-01-01', 'لا يمكن أن يكون تاريخ الميلاد في المستقبل.'],
            'individual.birth_date ' => ['1990-02-30', 'أدخل تاريخ ميلاد حقيقياً بالتقويم الميلادي.'],
            'individual.nationality' => ['XX', 'اختر الجنسية.'],
            'individual.gender' => ['other', 'اختر الجنس.'],
            'individual.first_name' => ['Layla1', 'استخدم الحروف والمسافات وعلامات الأسماء المعتادة فقط في الاسم الأول.'],
            'individual.national_id_number' => ['12<script>', 'استخدم الحروف والأرقام والفواصل المعتادة (- / .) فقط في رقم الهوية.'],
            'individual.primary_phone.number' => ['12345', 'رقم الهاتف غير صحيح لرمز الدولة المختار. تحقّق من الرقم ورمز الدولة.'],
            'individual.contact_email' => ['not-an-email', 'أدخل البريد الإلكتروني للتواصل صحيحاً، مثل name@example.com.'],
            'individual.address' => [str_repeat('ع', 501), 'يجب ألا يزيد العنوان على 500 حرفاً.'],
        ];

        foreach ($cases as $field => [$value, $message]) {
            $field = trim($field);
            $payload = $this->individualPayload();
            data_set($payload, $field, $value);
            $errors = $this->confirm($payload)->assertUnprocessable()->json('errors');
            $this->assertSame($message, $errors[$field][0] ?? null, $field);
        }

        foreach (['javascript:alert(1)', 'ftp://example.org/file', 'https://user:pass@example.org/', 'https://localhost', 'example.org'] as $url) {
            $payload = $this->individualPayload();
            $payload['social_links'][0]['url'] = $url;
            $this->confirm($payload)->assertUnprocessable()->assertJsonValidationErrors(['social_links.0.url']);
        }

        $payload = $this->individualPayload();
        $payload['social_links'] = array_fill(0, 6, ['platform' => 'X', 'url' => 'https://x.example/me']);
        $this->confirm($payload)->assertUnprocessable()->assertJsonPath('errors.social_links.0', 'يمكن إضافة 5 روابط على الأكثر.');

        // A number must belong to the selected country.
        $payload = $this->individualPayload();
        $payload['individual']['primary_phone'] = ['country' => 'DE', 'number' => '+963944123456'];
        $this->confirm($payload)->assertUnprocessable()->assertJsonValidationErrors(['individual.primary_phone.number']);

        $this->assertNothingStored();
    }

    public function test_arabic_digits_and_optional_fields_are_normalized(): void
    {
        $this->signIn();
        $payload = $this->individualPayload();
        $payload['individual']['birth_date'] = '١٩٨٨-٠٣-٠٥';
        $payload['individual']['national_id_number'] = '۰۱۲۳۴';
        $payload['individual']['primary_phone']['number'] = '٠٩٤٤ ١٢٣ ٤٥٦';
        $payload['individual']['middle_name'] = '';
        $payload['individual']['secondary_phone'] = ['country' => 'SY', 'number' => ''];
        $payload['social_links'] = [];

        $this->confirm($payload)->assertCreated()
            ->assertJsonPath('data.individual.birth_date', '1988-03-05')
            ->assertJsonPath('data.individual.national_id_number', '01234')
            ->assertJsonPath('data.individual.primary_phone', '+963944123456')
            ->assertJsonPath('data.individual.middle_name', null)
            ->assertJsonPath('data.individual.secondary_phone', null)
            ->assertJsonPath('data.social_links', []);
    }

    public function test_a_section_can_be_checked_without_saving(): void
    {
        $this->signIn();
        $payload = $this->individualPayload();

        $this->check('individual_identity', $payload)->assertNoContent();

        // Only the requested section is checked.
        $payload['individual']['primary_phone']['number'] = '1';
        $this->check('individual_identity', $payload)->assertNoContent();
        $this->check('individual_contact', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['individual.primary_phone.number'])
            ->assertJsonMissingValidationErrors(['individual.first_name']);

        $this->check('institution_details', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['section']);
        $this->assertNothingStored();
    }

    public function test_confirmation_needs_a_verified_email_and_published_policies(): void
    {
        $unverified = User::factory()->candidate()->unverified()->create(['email' => 'new@example.org']);
        $this->signIn($unverified->email);

        // The dashboard and the form stay available while unverified.
        $this->browser('GET', '/api/candidate/overview')->assertOk();
        $this->browser('GET', '/api/candidate/basic-profile')->assertOk()->assertJsonPath('requirements.email_verified', false);
        $this->confirm($this->individualPayload())->assertForbidden()->assertJsonPath('code', 'email_unverified');
        $this->assertNothingStored();

        // Without published policies, confirmation is unavailable.
        $this->signIn();
        AccountPolicyVersion::query()->where('status', AccountPolicyVersion::STATUS_PUBLISHED)->update(['status' => AccountPolicyVersion::STATUS_RETIRED]);
        $this->browser('GET', '/api/candidate/overview')->assertOk()->assertJsonPath('data.basic_information.status', 'unavailable');
        $this->browser('GET', '/api/candidate/basic-profile')->assertOk()
            ->assertJsonPath('requirements.finalization_available', false)
            ->assertJsonPath('requirements.policies.privacy_policy', null);
        $this->confirm($this->individualPayload())->assertConflict()->assertJsonPath('code', 'policies_not_configured');
        $this->assertNothingStored();
    }

    public function test_a_policy_published_after_the_form_was_opened_must_be_accepted_again(): void
    {
        $this->signIn();
        $payload = $this->individualPayload();
        $this->artisanPublish('terms_of_use');
        $newTerms = AccountPolicyVersion::query()->where('code', 'terms_of_use')->where('status', 'published')->sole();

        $this->confirm($payload)->assertConflict()
            ->assertJsonPath('code', 'policy_changed')
            ->assertJsonPath('requirements.policies.terms_of_use.id', $newTerms->id)
            ->assertJsonPath('requirements.policies.terms_of_use.version_number', 2);
        $this->assertNothingStored();

        $payload['accepted_policies']['terms_of_use'] = $newTerms->id;
        $this->confirm($payload)->assertCreated();
        $this->assertSame(
            [$newTerms->id, $this->policies['privacy_policy']->id],
            DB::table('account_policy_acceptances')->orderByDesc('policy_version_id')->pluck('policy_version_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_the_confirmed_information_is_locked_for_every_write(): void
    {
        $this->signIn();
        $this->confirm($this->individualPayload())->assertCreated();
        $snapshot = $this->snapshot();

        // A retry (double click, second tab, lost response) gets the stored result.
        $this->confirm($this->individualPayload())->assertConflict()
            ->assertJsonPath('code', 'profile_already_completed')
            ->assertJsonPath('data.individual.first_name', 'Zoë');

        // Replacing or switching the type is refused too.
        $changed = $this->individualPayload();
        $changed['individual']['first_name'] = 'Changed';
        $this->confirm($changed)->assertConflict()->assertJsonPath('code', 'profile_already_completed');
        $this->confirm($this->institutionPayload())->assertConflict()->assertJsonPath('data.profile_type', 'individual');

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->browser($method, '/api/candidate/basic-profile', $changed)->assertMethodNotAllowed();
        }
        $this->check('individual_identity', $changed)->assertNoContent();

        $this->assertSame($snapshot, $this->snapshot(), 'nothing may change after confirmation');
    }

    public function test_the_information_persists_across_sign_out_and_sign_in(): void
    {
        $this->signIn();
        $this->confirm($this->institutionPayload())->assertCreated();
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $this->browser('GET', '/api/candidate/basic-profile')->assertUnauthorized();

        $this->signIn();
        $this->assertPrivate($this->browser('GET', '/api/candidate/basic-profile')->assertOk()
            ->assertJsonPath('data.profile_type', 'institution')
            ->assertJsonPath('data.institution.activity_type', 'صون التراث اللامادي')
            ->assertJsonPath('data.accepted_policies.0.version_number', 1));
    }

    public function test_a_form_resumes_after_its_own_account_signs_in_again(): void
    {
        // A opens the form, then the session ends (sign-out or expiry).
        $this->signIn();
        $context = $this->contextFromForm();
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $this->confirm($this->individualPayload() + ['account_context' => $context])->assertUnauthorized();
        $this->assertNothingStored();

        // A signs in again in a new session: the open form carries on.
        $this->signIn();
        $this->check('individual_identity', $this->individualPayload() + ['account_context' => $context])->assertNoContent();
        $this->confirm($this->individualPayload() + ['account_context' => $context])->assertCreated();
        $this->assertSame($this->candidate->id, (int) DB::table('candidate_profiles')->sole()->user_id);
    }

    public function test_a_form_opened_for_another_account_is_refused_without_any_write(): void
    {
        $other = User::factory()->candidate()->create(['email' => 'other@example.org']);
        $this->signIn();
        $contextOfA = $this->contextFromForm();

        // The browser is now signed in as B, but the open form is A's.
        $this->signIn($other->email);
        $this->assertNotSame($contextOfA, $this->contextFromForm());
        $this->check('individual_identity', $this->individualPayload() + ['account_context' => $contextOfA])
            ->assertConflict()->assertJsonPath('code', 'account_mismatch');
        $this->confirm($this->individualPayload() + ['account_context' => $contextOfA])
            ->assertConflict()->assertJsonPath('code', 'account_mismatch')->assertJsonMissingPath('data');
        $this->confirm($this->institutionPayload() + ['account_context' => $contextOfA])->assertConflict();

        // A missing or forged context is refused the same way.
        $this->confirm($this->individualPayload() + ['account_context' => null])->assertConflict()->assertJsonPath('code', 'account_mismatch');
        $this->confirm($this->individualPayload() + ['account_context' => str_repeat('0', 64)])->assertConflict();

        // Nothing was created for B from A's data, and A has nothing either.
        $this->assertNothingStored();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'candidate_profile.completed')->count());
    }

    public function test_another_accounts_completed_profile_is_never_returned_to_a_stale_form(): void
    {
        $other = User::factory()->candidate()->create(['email' => 'other@example.org']);
        $this->signIn();
        $contextOfA = $this->contextFromForm();

        // B has already confirmed their own information.
        $this->signIn($other->email);
        $this->confirm($this->institutionPayload())->assertCreated();
        $snapshot = $this->snapshot();

        // A's form, retried in B's session, is not B's "already saved" result.
        $response = $this->confirm($this->individualPayload() + ['account_context' => $contextOfA])
            ->assertConflict()
            ->assertJsonPath('code', 'account_mismatch')
            ->assertJsonMissingPath('data');
        foreach (['جمعية التراث الحيّ', '0042/2019', 'info@heritage.example'] as $detailOfB) {
            $this->assertStringNotContainsString($detailOfB, $response->getContent());
        }
        $this->assertSame($snapshot, $this->snapshot());

        // B's own retry still finds B's stored result.
        $this->confirm($this->institutionPayload())->assertConflict()->assertJsonPath('code', 'profile_already_completed')
            ->assertJsonPath('data.institution.name', 'جمعية التراث الحيّ');
    }

    public function test_candidate_endpoints_need_an_active_signed_in_candidate_and_csrf(): void
    {
        $this->startCsrfSession();
        $this->browser('GET', '/api/candidate/overview')->assertUnauthorized();
        $this->confirm($this->individualPayload())->assertUnauthorized();

        // A staff account without the classification is refused.
        User::factory()->create(['email' => 'staff@example.org']);
        $this->signIn('staff@example.org');
        foreach (['/api/candidate/overview', '/api/candidate/basic-profile', '/api/candidate/reference-data'] as $uri) {
            $this->assertPrivate($this->browser('GET', $uri)->assertForbidden()->assertJsonPath('code', 'not_candidate'));
        }
        $this->confirm($this->individualPayload())->assertForbidden();

        // CSRF is enforced.
        $this->signIn();
        $this->browser('POST', '/api/candidate/basic-profile', $this->individualPayload(), withCsrfHeader: false)->assertStatus(419);

        // A disabled account loses its session.
        $this->candidate->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now()])->save();
        $this->browser('GET', '/api/candidate/overview')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->assertNothingStored();
    }

    public function test_reference_data_lists_iso_countries_with_calling_codes(): void
    {
        $this->signIn();

        $countries = collect($this->browser('GET', '/api/candidate/reference-data')->assertOk()->json('countries'))->keyBy('code');

        $this->assertCount(249, $countries);
        $this->assertSame(['code' => 'SY', 'name' => 'سوريا', 'calling_code' => 963], $countries['SY']);
        $this->assertSame('فلسطين', $countries['PS']['name']);
        $this->assertNull($countries['AQ']['calling_code']);
        $this->assertFalse($countries->has('XK'));
    }

    public function test_policies_are_readable_by_version_and_drafts_stay_hidden(): void
    {
        $this->getJson('/api/policies/privacy-policy')->assertOk()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', 'published');

        $this->artisanPublish('privacy_policy');
        $this->getJson('/api/policies/privacy-policy')->assertOk()->assertJsonPath('data.version_number', 2)->assertJsonPath('data.title', 'سياسة الخصوصية');
        $this->getJson('/api/policies/privacy-policy?version=1')->assertOk()->assertJsonPath('data.status', 'retired');

        AccountPolicyVersion::factory()->code('privacy_policy')->create(['version_number' => 3]);
        $this->getJson('/api/policies/privacy-policy?version=3')->assertNotFound()->assertJsonPath('code', 'policy_not_configured');
        $this->getJson('/api/policies/cookies')->assertNotFound();

        AccountPolicyVersion::query()->where('status', AccountPolicyVersion::STATUS_PUBLISHED)->update(['status' => AccountPolicyVersion::STATUS_RETIRED]);
        $this->getJson('/api/policies/terms-of-use')->assertNotFound()->assertJsonPath('code', 'policy_not_configured');
    }

    public function test_the_publish_command_publishes_the_next_version_and_retires_the_previous_one(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'policy');
        file_put_contents($file, "\xEF\xBB\xBFنص معتمد.\r\nالسطر الثاني.");

        $this->artisan('account-policies:publish', ['policy' => 'privacy_policy', 'file' => $file, '--title' => 'سياسة الخصوصية', '--dry-run' => true])
            ->expectsOutputToContain('Version: 2')
            ->assertSuccessful();
        $this->assertSame(1, AccountPolicyVersion::query()->where('code', 'privacy_policy')->count());

        $this->artisan('account-policies:publish', ['policy' => 'privacy_policy', 'file' => $file, '--title' => 'سياسة الخصوصية'])
            ->expectsConfirmation('Publish this approved text? New acceptances will require this version.', 'yes')
            ->assertSuccessful();
        unlink($file);

        $versions = AccountPolicyVersion::query()->where('code', 'privacy_policy')->orderBy('version_number')->get();
        $this->assertSame(['retired', 'published'], $versions->pluck('status')->all());
        $this->assertSame("نص معتمد.\nالسطر الثاني.", $versions[1]->body_ar);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'account_policy.published')->count());

        $this->artisan('account-policies:publish', ['policy' => 'cookies', 'file' => __FILE__, '--title' => 'x'])->assertExitCode(2);
    }

    private function signIn(?string $email = null): void
    {
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $email ?? $this->candidate->email, 'password' => 'password'])->assertOk();
        $this->signedIn = User::query()->where('email', $email ?? $this->candidate->email)->sole();
    }

    /**
     * The account context a form opened by the signed-in account receives.
     */
    private function contextFromForm(): string
    {
        return $this->browser('GET', '/api/candidate/basic-profile')->assertOk()->json('account_context');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function check(string $section, array $payload): TestResponse
    {
        return $this->browser('POST', '/api/candidate/basic-profile/check', ['section' => $section] + $this->withContext($payload));
    }

    /**
     * Adds the signed-in account's own form context, unless the payload
     * already carries one (for example a stale form of another account).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withContext(array $payload): array
    {
        return $payload + ['account_context' => $this->signedIn ? AccountContext::for($this->signedIn) : null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirm(array $payload): TestResponse
    {
        // Stay under the per-account confirmation limit (10 per minute).
        $this->travel(7)->seconds();

        return $this->browser('POST', '/api/candidate/basic-profile', $this->withContext($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function individualPayload(): array
    {
        return [
            'profile_type' => 'individual',
            'individual' => [
                'first_name' => 'Zoë',
                'middle_name' => 'مُحَمَّد',
                'last_name' => "O'Brien-Al  Hasan",
                'birth_date' => '1950-02-28',
                'nationality' => 'SY',
                'gender' => 'female',
                'national_id_number' => '00123/AB-9',
                'residence_country' => 'DE',
                'address' => 'Musterstraße 1, 10115 Berlin',
                'primary_phone' => ['country' => 'SY', 'number' => '0944 123 456'],
                'secondary_phone' => ['country' => 'DE', 'number' => '030 123456'],
                'contact_email' => 'contact@example.net',
            ],
            'social_links' => [['platform' => 'LinkedIn', 'url' => 'https://www.linkedin.com/in/layla']],
            'accepted_policies' => $this->acceptedPolicies(),
            'declarations' => ['data_accuracy' => true],
            // Never accepted from the browser:
            'completed_at' => '2020-01-01 00:00:00',
            'user_id' => 999,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionPayload(): array
    {
        return [
            'profile_type' => 'institution',
            'institution' => [
                'name' => 'جمعية التراث الحيّ',
                'country' => 'SY',
                'activity_type' => 'صون التراث اللامادي',
                'license_number' => '0042/2019',
                'representative_name' => 'Layla Hassan',
                'representative_title' => 'مديرة البرامج',
                'phone' => ['country' => 'SY', 'number' => '011 234 5678'],
                'email' => 'info@heritage.example',
                'website' => null,
            ],
            'social_links' => [],
            'accepted_policies' => $this->acceptedPolicies(),
            'declarations' => ['data_accuracy' => true, 'representation' => true],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function acceptedPolicies(): array
    {
        return array_map(fn (AccountPolicyVersion $policy): int => $policy->id, $this->policies);
    }

    private function artisanPublish(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'policy');
        file_put_contents($file, 'نص تجريبي لإصدار جديد.');
        $this->artisan('account-policies:publish', ['policy' => $code, 'file' => $file, '--title' => AccountPolicyVersion::NAMES[$code], '--force' => true])
            ->assertSuccessful();
        unlink($file);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    private function assertNothingStored(): void
    {
        foreach (self::PROFILE_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return collect([...self::PROFILE_TABLES, 'users', 'user_roles'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }
}
