<?php

namespace Tests\Feature\Candidate;

use App\Candidates\AccountContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Stage 2 test helpers: an operator-configured cycle, a candidate with a
 * confirmed Stage 1, signed-in SPA requests carrying the account context
 * and journey key, and a complete valid payload.
 */
trait InteractsWithNominationStage
{
    private const STAGE_TABLES = ['applications', 'application_locations', 'application_partnerships', 'candidate_nominations'];

    private User $candidate;

    private User $operator;

    private ?User $signedIn = null;

    private function configureCycle(string $code = 'cycle-2026'): void
    {
        $this->runSetup($code, [
            '--create-cycle' => true, '--name' => "الدورة {$code}", '--award-name' => 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع',
            '--year' => '2026', '--timezone' => 'Asia/Damascus', '--country' => 'SY', '--open-domains' => true, '--enable' => true, '--publish-cycle' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runSetup(string $cycle, array $options): void
    {
        $this->artisan('nomination:setup', ['cycle' => $cycle, '--actor' => $this->operator->email] + $options)->assertSuccessful();
    }

    /**
     * Stage 1 as the confirmation would leave it (inserted directly: Stage 1
     * itself is covered by BasicProfileTest).
     *
     * @return array{applicant_id: int}
     */
    private function completeStage1(User $user): array
    {
        $applicantId = DB::table('applicants')->insertGetId([
            'type' => 'individual', 'name_ar' => 'ليلى حسن', 'representative_user_id' => $user->id, 'status' => 'active',
        ]);
        DB::table('candidate_profiles')->insert([
            'user_id' => $user->id, 'applicant_id' => $applicantId, 'profile_type' => 'individual',
            'data_accuracy_declared_at' => now(), 'completed_at' => now(), 'created_at' => now(),
        ]);

        return ['applicant_id' => $applicantId];
    }

    private function readyCandidate(): void
    {
        $this->configureCycle();
        $this->completeStage1($this->candidate);
        $this->signIn();
    }

    private function signIn(?string $email = null): void
    {
        $this->cookieJar = [];
        $this->startCsrfSession();
        $this->browser('POST', '/api/auth/login', ['email' => $email ?? $this->candidate->email, 'password' => 'password'])->assertOk();
        $this->signedIn = User::query()->where('email', $email ?? $this->candidate->email)->sole();
    }

    private function read(string $uri, ?string $context = null): TestResponse
    {
        return $this->browser('GET', $uri, headers: ['X-Candidate-Context' => $context ?? AccountContext::for($this->signedIn)]);
    }

    /**
     * The reconciliation read of one form's journey.
     */
    private function reconcile(string $journeyKey, ?string $context = null): TestResponse
    {
        return $this->browser('GET', '/api/candidate/nomination-details/journey', headers: [
            'X-Candidate-Context' => $context ?? AccountContext::for($this->signedIn),
            'X-Nomination-Journey' => $journeyKey,
        ]);
    }

    private function journeyKey(): string
    {
        return (string) $this->read('/api/candidate/nomination-details')->assertOk()->json('journey_key');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $uri, array $payload, ?string $journeyKey = null): TestResponse
    {
        // Stay under the per-account limits.
        $this->travel(7)->seconds();

        return $this->browser('POST', $uri, $payload + [
            'account_context' => $this->signedIn ? AccountContext::for($this->signedIn) : null,
            'journey_key' => $journeyKey ?? $this->journeyKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function check(string $section, array $payload, ?string $journeyKey = null): TestResponse
    {
        return $this->send('/api/candidate/nomination-details/check', ['section' => $section] + $payload, $journeyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirm(array $payload, ?string $journeyKey = null): TestResponse
    {
        return $this->send('/api/candidate/nomination-details', $payload, $journeyKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'domain' => 'creativity',
            'subcategory' => 'quality',
            'title' => 'مبادرة ذاكرة الحيّ',
            'purpose_and_mission' => 'صون ذاكرة الأحياء القديمة ونقلها إلى الأجيال الجديدة.',
            'objectives' => "توثيق الحِرف التقليدية\nتدريب الشباب على التوثيق",
            'impact' => 'وثّقنا ثلاثين حرفة، ودرّبنا أربعين شاباً، وأقمنا معرضاً زاره أهل الحي.',
            'implementation_state' => 'ongoing',
            'started_on' => '2021-09-15',
            'ended_on' => null,
            'locations' => [['governorate' => 'SY-DI', 'locality' => 'دمشق القديمة', 'address' => 'حي القيمرية']],
            'funding_type' => 'fully_funded',
            'funder_name' => 'مؤسسة الروّاد',
            'funding_amount' => '1500.50',
            'has_partnerships' => false,
            // Never accepted from the browser:
            'status' => 'submitted',
            'first_submitted_at' => '2026-01-01 00:00:00',
            'applicant_id' => 999,
            'user_id' => 999,
        ];
    }

    private function cycleDomain(string $cycle, string $domain): object
    {
        return DB::table('cycle_domains')
            ->join('award_cycles', 'award_cycles.id', '=', 'cycle_domains.cycle_id')
            ->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
            ->where('award_cycles.code', $cycle)->where('award_domains.code', $domain)
            ->first(['cycle_domains.id', 'cycle_domains.cycle_id']);
    }

    private function subcategoryId(string $cycle, string $code): int
    {
        return (int) DB::table('cycle_domain_subcategories')
            ->join('award_cycles', 'award_cycles.id', '=', 'cycle_domain_subcategories.cycle_id')
            ->where('award_cycles.code', $cycle)->where('cycle_domain_subcategories.code', $code)
            ->value('cycle_domain_subcategories.id');
    }

    /**
     * The DECIMAL(14,2) value as exact text, whatever the driver returns.
     */
    private function decimal(mixed $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function assertNothingStored(): void
    {
        foreach (self::STAGE_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return collect([...self::STAGE_TABLES, 'applicants'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }
}
