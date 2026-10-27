<?php

namespace Tests\Feature\Candidate;

use App\Candidates\AccountContext;
use App\Models\ApplicationPartnership;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\ActsAsSpaBrowser;
use Tests\TestCase;

/**
 * Stage 2 («فئة الترشح وبيانات العمل»): prerequisites, availability, the
 * approved taxonomy, validation of every branch, the atomic confirmation
 * that leaves the application a draft, read-only retries, and the account
 * and journey bindings. Requests use real cookies and CSRF.
 */
class NominationDetailsTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'operator@example.org']);
        $this->candidate = User::factory()->candidate()->create(['email' => 'layla@example.org']);
    }

    public function test_stage_2_needs_a_confirmed_stage_1(): void
    {
        $this->configureCycle();
        $this->signIn();

        $this->read('/api/candidate/nomination-details')->assertConflict()->assertJsonPath('code', 'basic_information_required');
        $this->read('/api/candidate/overview')->assertOk()->assertJsonPath('data.nomination_details.status', 'locked');
        $this->send('/api/candidate/nomination-details/check', ['section' => 'domain'] + $this->payload(), 'any-key')
            ->assertConflict()->assertJsonPath('code', 'basic_information_required');
        $this->send('/api/candidate/nomination-details', $this->payload(), 'any-key')
            ->assertConflict()->assertJsonPath('code', 'basic_information_required');
        $this->assertNothingStored();
    }

    public function test_the_stage_needs_exactly_one_configured_cycle(): void
    {
        $this->completeStage1($this->candidate);
        $this->signIn();

        // No cycle yet.
        $this->read('/api/candidate/nomination-details')->assertOk()->assertJsonPath('availability.status', 'not_configured')->assertJsonMissingPath('journey_key');
        $this->read('/api/candidate/overview')->assertJsonPath('data.nomination_details.status', 'unavailable');

        // Configured but not enabled or not published: still unavailable.
        $this->runSetup('cycle-2026', ['--create-cycle' => true, '--name' => 'الدورة الأولى', '--award-name' => 'جائزة', '--year' => '2026', '--timezone' => 'Asia/Damascus', '--country' => 'SY', '--open-domains' => true]);
        $this->read('/api/candidate/nomination-details')->assertJsonPath('availability.status', 'not_configured');
        $this->runSetup('cycle-2026', ['--enable' => true]);
        $this->read('/api/candidate/nomination-details')->assertJsonPath('availability.status', 'not_configured');
        $this->runSetup('cycle-2026', ['--publish-cycle' => true]);
        $this->read('/api/candidate/nomination-details')->assertOk()->assertJsonPath('availability.status', 'available')->assertJsonPath('cycle.code', 'cycle-2026');
        $this->read('/api/candidate/overview')->assertJsonPath('data.nomination_details.status', 'not_started');

        // A second usable cycle is ambiguous: none is chosen by default.
        $this->configureCycle('cycle-2027');
        $this->read('/api/candidate/nomination-details')->assertJsonPath('availability.status', 'ambiguous');
        $this->send('/api/candidate/nomination-details', $this->payload(), 'any-key')->assertConflict()->assertJsonPath('code', 'nomination_unavailable');
        $this->assertNothingStored();
    }

    public function test_the_approved_taxonomy_and_artwork_are_offered(): void
    {
        $this->configureCycle();
        $this->completeStage1($this->candidate);
        $this->signIn();

        $domains = $this->read('/api/candidate/nomination-details')->assertOk()->json('options.domains');

        $this->assertSame([
            ['code' => 'culture_and_arts', 'name' => 'الثقافة والفنون', 'artwork' => 'domain-culture-and-arts', 'subcategories' => [['code' => 'culture', 'name' => 'الثقافة'], ['code' => 'arts', 'name' => 'الفنون']]],
            ['code' => 'heritage', 'name' => 'التراث', 'artwork' => 'domain-tangible-and-intangible-heritage', 'subcategories' => [['code' => 'tangible_heritage', 'name' => 'التراث المادي'], ['code' => 'intangible_heritage', 'name' => 'التراث اللامادي']]],
            // Creativity uses the approved former Volunteer Work artwork.
            ['code' => 'creativity', 'name' => 'الإبداع', 'artwork' => 'domain-volunteer-work', 'subcategories' => [
                ['code' => 'social_responsibility', 'name' => 'المسؤولية المجتمعية'], ['code' => 'entrepreneurship', 'name' => 'ريادة الأعمال'], ['code' => 'quality', 'name' => 'الجودة'],
            ]],
        ], $domains);

        $pairs = ['culture_and_arts' => ['culture', 'arts'], 'heritage' => ['tangible_heritage', 'intangible_heritage'], 'creativity' => ['social_responsibility', 'entrepreneurship', 'quality']];
        foreach ($pairs as $domain => $subcategories) {
            foreach ($subcategories as $subcategory) {
                $this->check('domain', ['domain' => $domain, 'subcategory' => $subcategory])->assertNoContent();
            }
        }
        $this->assertCount(14, $this->read('/api/candidate/nomination-details')->json('options.governorates'));
    }

    public function test_invalid_domain_and_subcategory_choices_are_refused(): void
    {
        $this->configureCycle();
        $this->completeStage1($this->candidate);
        $this->signIn();

        $this->check('domain', ['domain' => 'culture_and_arts', 'subcategory' => 'quality'])->assertUnprocessable()->assertJsonValidationErrors(['subcategory']);
        $this->check('domain', ['domain' => 'culture_and_arts'])->assertUnprocessable()->assertJsonValidationErrors(['subcategory']);
        $this->check('domain', ['subcategory' => 'arts'])->assertUnprocessable()->assertJsonValidationErrors(['domain']);
        $this->check('domain', ['domain' => 'volunteer_work', 'subcategory' => 'arts'])->assertUnprocessable()->assertJsonValidationErrors(['domain']);
        $this->check('domain', ['domain' => ['culture_and_arts', 'heritage'], 'subcategory' => ['culture', 'arts']])
            ->assertUnprocessable()->assertJsonValidationErrors(['domain', 'subcategory']);

        // An inactive subcategory or a closed domain is not available.
        DB::table('cycle_domain_subcategories')->where('code', 'arts')->update(['is_active' => false]);
        $this->check('domain', ['domain' => 'culture_and_arts', 'subcategory' => 'arts'])->assertUnprocessable()->assertJsonValidationErrors(['subcategory']);
        DB::table('cycle_domains')->where('name_ar', 'التراث')->update(['is_open_for_applications' => false]);
        $this->check('domain', ['domain' => 'heritage', 'subcategory' => 'tangible_heritage'])->assertUnprocessable()->assertJsonValidationErrors(['domain']);
        $this->assertSame(['culture_and_arts', 'creativity'], array_column($this->read('/api/candidate/nomination-details')->json('options.domains'), 'code'));
    }

    public function test_the_database_refuses_a_subcategory_of_another_domain_or_cycle(): void
    {
        $this->configureCycle('cycle-a');
        $this->configureCycle('cycle-b');
        $applicant = $this->completeStage1($this->candidate)['applicant_id'];
        $domainA = $this->cycleDomain('cycle-a', 'culture_and_arts');
        $insert = fn (int $subcategoryId) => DB::table('applications')->insert([
            'cycle_id' => $domainA->cycle_id, 'cycle_domain_id' => $domainA->id, 'subcategory_id' => $subcategoryId,
            'applicant_id' => $applicant, 'created_by' => $this->candidate->id, 'status' => 'draft',
        ]);

        foreach ([$this->subcategoryId('cycle-a', 'quality'), $this->subcategoryId('cycle-b', 'arts')] as $foreign) {
            try {
                $insert($foreign);
                $this->fail('A subcategory of another domain or cycle was accepted.');
            } catch (QueryException) {
                $this->assertSame(0, DB::table('applications')->count());
            }
        }
        $insert($this->subcategoryId('cycle-a', 'arts'));
        $this->assertSame(1, DB::table('applications')->count());
    }

    public function test_dates_follow_the_work_state(): void
    {
        $this->readyCandidate();
        $cases = [
            [['implementation_state' => 'ongoing', 'ended_on' => '2024-01-01'], 'ended_on', 'لا يُقبل تاريخ انتهاء لعمل مستمر.'],
            [['implementation_state' => 'completed', 'ended_on' => null], 'ended_on', 'أدخل تاريخ انتهاء العمل، أو اختر «العمل مستمر».'],
            [['implementation_state' => 'completed', 'started_on' => '2024-05-02', 'ended_on' => '2024-05-01'], 'ended_on', 'لا يمكن أن يسبق تاريخ الانتهاء تاريخ البدء.'],
            [['started_on' => now('Asia/Damascus')->addDay()->toDateString()], 'started_on', 'لا يمكن أن يكون تاريخ بدء العمل في المستقبل.'],
            [['started_on' => '2023-02-30'], 'started_on', 'أدخل تاريخ بدء حقيقياً بالتقويم الميلادي.'],
            [['implementation_state' => 'completed', 'ended_on' => now('Asia/Damascus')->addDay()->toDateString()], 'ended_on', 'لا يمكن أن يكون تاريخ انتهاء العمل في المستقبل.'],
            [['implementation_state' => 'paused'], 'implementation_state', 'حدّد إن كان العمل مستمراً أو منتهياً.'],
        ];
        foreach ($cases as [$override, $field, $message]) {
            $this->assertSame($message, $this->check('dates_locations', $override + $this->payload())->assertUnprocessable()->json("errors.{$field}.0"), $field);
        }

        // Completed today (Damascus) with Arabic digits; stored as calendar dates.
        $today = now('Asia/Damascus')->toDateString();
        $this->confirm(['implementation_state' => 'completed', 'started_on' => '٢٠١٩-٠٣-٠١', 'ended_on' => $today] + $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.implementation', ['state' => 'completed', 'started_on' => '2019-03-01', 'ended_on' => $today]);
        $application = DB::table('applications')->sole();
        $this->assertSame(['2019-03-01', $today, 'completed'], [substr($application->implementation_started_on, 0, 10), substr($application->implementation_ended_on, 0, 10), $application->implementation_status]);
    }

    public function test_ongoing_work_has_no_end_date(): void
    {
        $this->readyCandidate();

        $this->confirm(['implementation_state' => 'ongoing', 'ended_on' => null] + $this->payload())
            ->assertCreated()->assertJsonPath('data.implementation', ['state' => 'ongoing', 'started_on' => '2021-09-15', 'ended_on' => null]);
        $this->assertNull(DB::table('applications')->value('implementation_ended_on'));
    }

    public function test_several_places_are_kept_and_duplicates_refused(): void
    {
        $this->readyCandidate();

        $duplicate = ['locations' => [
            ['governorate' => 'SY-DI', 'locality' => 'دمشق القديمة', 'address' => null],
            ['governorate' => 'SY-DI', 'locality' => '  دمشق   القديمة ', 'address' => ''],
        ]] + $this->payload();
        $this->assertSame('مكان التنفيذ هذا مكرّر. احذفه أو غيّر بياناته.', $this->check('dates_locations', $duplicate)->assertUnprocessable()->json('errors')['locations.1.locality'][0]);
        $this->check('dates_locations', ['locations' => []] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['locations']);
        $this->check('dates_locations', ['locations' => [['governorate' => 'IQ-BG', 'locality' => 'بغداد']]] + $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors(['locations.0.governorate']);
        $this->check('dates_locations', ['locations' => array_fill(0, 21, ['governorate' => 'SY-HL', 'locality' => 'x'])] + $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors(['locations']);

        $this->confirm(['locations' => [
            ['governorate' => 'SY-HL', 'locality' => 'حلب', 'address' => 'حي الجديدة'],
            ['governorate' => 'SY-HL', 'locality' => 'منبج', 'address' => null],
            ['governorate' => 'SY-SU', 'locality' => 'شهبا', 'address' => null],
        ]] + $this->payload())->assertCreated()->assertJsonCount(3, 'data.locations')
            ->assertJsonPath('data.locations.0.governorate_name', 'حلب');
        $this->assertSame(
            [['SY', 'SY-HL', 'حلب', 'حي الجديدة'], ['SY', 'SY-HL', 'منبج', null], ['SY', 'SY-SU', 'شهبا', null]],
            DB::table('application_locations')->orderBy('id')->get()->map(fn ($row): array => [$row->country_code, $row->governorate, $row->locality, $row->address])->all(),
        );
    }

    public function test_a_section_check_is_not_blocked_by_unfinished_places_further_on(): void
    {
        $this->readyCandidate();

        // Two blank place rows added, then back to an earlier section.
        $blank = ['governorate' => '', 'locality' => '', 'address' => ''];
        $unfinished = ['locations' => [$blank, $blank]] + $this->payload();
        foreach (['domain', 'work', 'funding'] as $section) {
            $this->check($section, $unfinished)->assertNoContent();
        }

        // The places' own section still reports them, where they can be fixed.
        $this->check('dates_locations', $unfinished)->assertUnprocessable()
            ->assertJsonValidationErrors(['locations.0.governorate', 'locations.0.locality', 'locations.1.governorate', 'locations.1.locality']);
        $this->assertNothingStored();
    }

    public function test_duplicate_places_are_still_refused_by_their_section_and_the_confirmation(): void
    {
        $this->readyCandidate();
        $duplicate = ['locations' => [
            ['governorate' => 'SY-HM', 'locality' => 'حماة', 'address' => null],
            ['governorate' => 'SY-HM', 'locality' => 'حماة', 'address' => null],
        ]] + $this->payload();

        $this->check('work', $duplicate)->assertNoContent();
        $this->check('dates_locations', $duplicate)->assertUnprocessable()->assertJsonValidationErrors(['locations.1.locality']);
        $this->confirm($duplicate)->assertUnprocessable()->assertJsonValidationErrors(['locations.1.locality']);
        $this->assertNothingStored();
    }

    public function test_each_funding_declaration_keeps_only_its_own_fields(): void
    {
        $this->configureCycle();
        $branches = [
            'fully_funded' => [['funding_type' => 'fully_funded', 'funder_name' => 'مؤسسة الروّاد', 'funding_amount' => '1500.50'], ['مؤسسة الروّاد', '1500.50']],
            'partially_funded' => [['funding_type' => 'partially_funded', 'funder_name' => 'جهة داعمة', 'funding_amount' => '١٢٠٠٫٥'], ['جهة داعمة', '1200.50']],
            'self_funded' => [['funding_type' => 'self_funded', 'funding_amount' => '250'], [null, '250.00']],
            'not_needed' => [['funding_type' => 'not_needed'], [null, null]],
        ];

        foreach ($branches as $type => [$fields, [$funder, $amount]]) {
            $user = User::factory()->candidate()->create(['email' => "{$type}@example.org"]);
            $this->completeStage1($user);
            $this->signIn($user->email);
            $payload = $fields + array_diff_key($this->payload(), array_flip(['funding_type', 'funder_name', 'funding_amount']));
            $this->confirm($payload)->assertCreated()
                ->assertJsonPath('data.funding', ['type' => $type, 'funder_name' => $funder, 'amount_usd' => $amount]);
            $stored = DB::table('applications')->where('created_by', $user->id)->sole();
            $this->assertSame([$type, $funder], [$stored->funding_type, $stored->funder_name]);
            $this->assertSame($amount, $stored->funding_amount_usd === null ? null : $this->decimal($stored->funding_amount_usd));
        }
    }

    public function test_funding_fields_that_do_not_apply_and_ambiguous_amounts_are_refused(): void
    {
        $this->readyCandidate();
        $base = array_diff_key($this->payload(), array_flip(['funding_type', 'funder_name', 'funding_amount']));
        $refused = [
            [['funding_type' => 'self_funded', 'funder_name' => 'جهة', 'funding_amount' => '10'], 'funder_name'],
            [['funding_type' => 'not_needed', 'funding_amount' => '10'], 'funding_amount'],
            [['funding_type' => 'not_needed', 'funder_name' => 'جهة'], 'funder_name'],
            [['funding_type' => 'fully_funded', 'funding_amount' => '10'], 'funder_name'],
            [['funding_type' => 'partially_funded', 'funder_name' => 'جهة'], 'funding_amount'],
            [['funding_type' => 'self_funded'], 'funding_amount'],
            [['funding_type' => 'grant'], 'funding_type'],
            [['funding_type' => null], 'funding_type'],
        ];
        foreach ($refused as [$fields, $field]) {
            $this->check('funding', $fields + $base)->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        foreach (['1,500', '1٬500', '0', '0.00', '10.001', '-5', '1e3', ' ', 'abc', '1234567890123'] as $amount) {
            $this->check('funding', ['funding_type' => 'self_funded', 'funding_amount' => $amount] + $base)
                ->assertUnprocessable()->assertJsonValidationErrors(['funding_amount']);
        }
        $this->check('funding', ['funding_type' => 'self_funded', 'funding_amount' => 1500.5] + $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['funding_amount']);
        $this->check('funding', ['funding_type' => 'self_funded', 'funding_amount' => '999999999999.99'] + $base)->assertNoContent();
    }

    public function test_partnerships_follow_the_yes_or_no_answer(): void
    {
        $this->readyCandidate();
        $base = array_diff_key($this->payload(), array_flip(['has_partnerships', 'partnerships']));

        $this->check('funding', ['has_partnerships' => true, 'partnerships' => []] + $base)->assertUnprocessable()->assertJsonValidationErrors(['partnerships']);
        $this->check('funding', ['has_partnerships' => true] + $base)->assertUnprocessable()->assertJsonValidationErrors(['partnerships']);
        $this->check('funding', ['has_partnerships' => true, 'partnerships' => [['name' => '', 'contribution' => 'دعم']]] + $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['partnerships.0.name']);
        $this->check('funding', ['has_partnerships' => false, 'partnerships' => [['name' => 'جهة']]] + $base)->assertUnprocessable()->assertJsonValidationErrors(['partnerships']);
        $this->check('funding', ['has_partnerships' => null] + $base)->assertUnprocessable()->assertJsonValidationErrors(['has_partnerships']);
        $this->check('funding', ['has_partnerships' => true, 'partnerships' => array_fill(0, 11, ['name' => 'جهة'])] + $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['partnerships']);

        $this->confirm(['has_partnerships' => true, 'partnerships' => [
            ['name' => 'جمعية أصدقاء دمشق', 'contribution' => "تدريب المتطوعين\nوتأمين القاعة"],
            ['name' => 'Heritage Lab', 'contribution' => null],
        ]] + $base)->assertCreated()
            ->assertJsonPath('data.partnerships.has_partnerships', true)
            ->assertJsonPath('data.partnerships.items.0.contribution', "تدريب المتطوعين\nوتأمين القاعة")
            ->assertJsonCount(2, 'data.partnerships.items');
        $this->assertSame([1, 2], DB::table('application_partnerships')->orderBy('position')->pluck('position')->map(fn ($p): int => (int) $p)->all());
    }

    public function test_without_partners_no_rows_are_stored(): void
    {
        $this->readyCandidate();

        $this->confirm(['has_partnerships' => false] + array_diff_key($this->payload(), array_flip(['partnerships'])))
            ->assertCreated()->assertJsonPath('data.partnerships', ['has_partnerships' => false, 'items' => []]);
        $this->assertSame(0, DB::table('application_partnerships')->count());
    }

    public function test_text_is_plain_unicode_and_never_truncated(): void
    {
        $this->readyCandidate();

        $this->check('work', ['title' => "عنوان\x07"] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['title']);
        $this->check('work', ['impact' => str_repeat('أ', 4001)] + $this->payload())->assertUnprocessable()->assertJsonPath('errors.impact.0', 'يجب ألا يزيد الأثر على 4000 حرفاً.');
        $this->check('work', ['objectives' => null] + $this->payload())->assertUnprocessable()->assertJsonPath('errors.objectives.0', 'أدخل الأهداف.');

        $this->confirm([
            'title' => '  <b>مُبادرة</b>   «ذاكرة الحيّ»  ',
            'objectives' => "١. توثيق الحِرف\r\n٢. تدريب الشباب",
            'impact' => str_repeat('أثر', 1333).'ٌ',
        ] + $this->payload())->assertCreated()
            ->assertJsonPath('data.title', '<b>مُبادرة</b> «ذاكرة الحيّ»')
            ->assertJsonPath('data.objectives', "١. توثيق الحِرف\n٢. تدريب الشباب");
        $this->assertSame(4000, mb_strlen((string) DB::table('applications')->value('documented_impact')));
    }

    public function test_confirmation_leaves_a_draft_and_is_read_only_afterwards(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        $this->confirm($this->payload(), $journeyKey)->assertCreated()
            ->assertJsonPath('data.domain', ['code' => 'creativity', 'name' => 'الإبداع', 'artwork' => 'domain-volunteer-work'])
            ->assertJsonPath('data.subcategory.code', 'quality');
        $application = DB::table('applications')->sole();
        $this->assertSame(['draft', null, null, null], [$application->status, $application->first_submitted_at, $application->current_version_id, $application->reference_number]);
        $this->assertSame(0, DB::table('application_versions')->count());
        $this->assertSame((int) DB::table('candidate_profiles')->value('applicant_id'), (int) $application->applicant_id);
        $this->assertSame(1, DB::table('applicants')->count(), 'no second applicant');
        $journey = DB::table('candidate_nominations')->sole();
        $this->assertSame([(int) $application->id, $this->candidate->id], [(int) $journey->application_id, (int) $journey->user_id]);

        // The audit entry holds codes and identifiers, never content or money.
        $audit = (string) DB::table('audit_logs')->where('action', 'nomination_details.confirmed')->sole()->after_data;
        foreach (['ذاكرة', 'دمشق', '1500', 'مؤسسة'] as $content) {
            $this->assertStringNotContainsString($content, $audit);
        }

        // Retries return the stored stage; nothing is replaced or added.
        $snapshot = $this->snapshot();
        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_details_already_confirmed')->assertJsonPath('data.subcategory.code', 'quality');
        $this->confirm(['domain' => 'heritage', 'subcategory' => 'tangible_heritage', 'title' => 'آخر'] + $this->payload(), $journeyKey)
            ->assertConflict()->assertJsonPath('data.title', 'مبادرة ذاكرة الحيّ');
        $this->check('domain', $this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_details_already_confirmed');
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->browser($method, '/api/candidate/nomination-details', $this->payload())->assertMethodNotAllowed();
        }
        $this->assertSame($snapshot, $this->snapshot());

        // Persisted across sign-out and sign-in; the dashboard sees only statuses.
        $this->browser('POST', '/api/auth/logout')->assertNoContent();
        $this->signIn();
        $this->assertPrivate($this->read('/api/candidate/nomination-details')->assertOk()->assertJsonPath('availability.status', 'confirmed')->assertJsonPath('data.funding.amount_usd', '1500.50'));
        $overview = $this->read('/api/candidate/overview')->assertOk()->assertJsonPath('data.nomination_details.status', 'completed')->getContent();
        foreach (['1500', 'مؤسسة الروّاد', 'دمشق القديمة', 'ذاكرة'] as $detail) {
            $this->assertStringNotContainsString($detail, $overview);
            $this->assertStringNotContainsString($detail, $this->browser('GET', '/api/user')->getContent());
        }
        $this->assertSame(1, DB::table('candidate_profiles')->where('user_id', $this->candidate->id)->count(), 'Stage 1 untouched');
    }

    public function test_a_failed_confirmation_leaves_nothing_behind(): void
    {
        $this->readyCandidate();
        ApplicationPartnership::creating(fn () => throw new RuntimeException('Simulated failure while saving a partner.'));
        $this->withoutExceptionHandling();

        try {
            $this->confirm(['has_partnerships' => true, 'partnerships' => [['name' => 'جهة', 'contribution' => null]]] + $this->payload());
            $this->fail('The simulated failure should surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure while saving a partner.', $exception->getMessage());
        }

        $this->assertNothingStored();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'nomination_details.confirmed')->count());
    }

    public function test_a_closed_stage_or_a_changed_cycle_refuses_the_open_form(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        $this->runSetup('cycle-2026', ['--close' => true]);
        $this->read('/api/candidate/nomination-details')->assertJsonPath('availability.status', 'not_configured');
        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_unavailable');
        $this->check('work', $this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_unavailable');

        // Another cycle is now the usable one: the open form's journey is stale.
        $this->configureCycle('cycle-2027');
        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'journey_changed');
        $this->assertNothingStored();

        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $this->assertSame('cycle-2027', DB::table('award_cycles')->where('id', DB::table('applications')->value('cycle_id'))->value('code'));
    }

    public function test_a_retry_after_the_stage_closed_returns_the_stored_result(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();
        $this->confirm($this->payload(), $journeyKey)->assertCreated();

        $this->runSetup('cycle-2026', ['--close' => true]);
        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_details_already_confirmed');
        // The only confirmed journey stays visible.
        $this->read('/api/candidate/nomination-details')->assertOk()->assertJsonPath('availability.status', 'confirmed');
        $this->read('/api/candidate/overview')->assertJsonPath('data.nomination_details.status', 'completed');
    }

    public function test_a_save_still_in_progress_when_reconciling_is_returned_by_the_retry(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();

        // Reconciliation read first: nothing visible yet; the original commits after.
        $this->read('/api/candidate/nomination-details')->assertJsonPath('data', null);
        $this->confirm($this->payload(), $journeyKey)->assertCreated();
        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_details_already_confirmed');
        $this->assertSame([1, 1, 1], [DB::table('applications')->count(), DB::table('candidate_nominations')->count(), DB::table('application_locations')->count()]);
    }

    public function test_reconciling_a_lost_save_never_takes_another_cycles_stage_for_it(): void
    {
        // Stage 2 confirmed in cycle A; A closes and cycle B opens.
        $this->readyCandidate();
        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $this->runSetup('cycle-2026', ['--close' => true]);
        $this->configureCycle('cycle-2027');

        // B's form is opened; its save gets no answer (and stored nothing).
        $keyOfB = $this->journeyKey();
        $this->reconcile($keyOfB)->assertOk()->assertJsonPath('data', null)->assertJsonPath('journey.status', 'open');

        // B closes before the form reconciles.
        $this->runSetup('cycle-2027', ['--close' => true]);
        // Browsing the page still shows the only confirmed stage, A's...
        $this->read('/api/candidate/nomination-details')->assertOk()->assertJsonPath('data.cycle.code', 'cycle-2026');
        // ...but B's reconciliation never takes it for B's save.
        $response = $this->reconcile($keyOfB)->assertOk()->assertJsonPath('data', null)->assertJsonPath('journey.status', 'unavailable');
        $this->assertStringNotContainsString('مبادرة ذاكرة الحيّ', $response->getContent());
        $this->assertPrivate($response);

        // Another cycle open instead: B's journey has changed; still nothing for B.
        $this->configureCycle('cycle-2028');
        $this->reconcile($keyOfB)->assertOk()->assertJsonPath('data', null)->assertJsonPath('journey.status', 'changed');
        $this->reconcile('not-a-journey-key')->assertOk()->assertJsonPath('data', null)->assertJsonPath('journey.status', 'changed');
        $this->assertSame(1, DB::table('candidate_nominations')->count());
    }

    public function test_a_confirmed_journey_stays_recoverable_after_closure_or_a_cycle_switch(): void
    {
        $this->readyCandidate();
        $keyOfA = $this->journeyKey();
        $this->confirm($this->payload(), $keyOfA)->assertCreated();
        $this->reconcile($keyOfA)->assertOk()->assertJsonPath('journey.status', 'confirmed')->assertJsonPath('data.cycle.code', 'cycle-2026');

        $this->runSetup('cycle-2026', ['--close' => true]);
        $this->reconcile($keyOfA)->assertJsonPath('journey.status', 'confirmed')->assertJsonPath('data.cycle.code', 'cycle-2026');

        // B opens and is confirmed too: each key gets its own journey only.
        $this->configureCycle('cycle-2027');
        $keyOfB = $this->journeyKey();
        $this->reconcile($keyOfA)->assertJsonPath('journey.status', 'confirmed')->assertJsonPath('data.cycle.code', 'cycle-2026');
        $this->confirm(['title' => 'عمل الدورة الثانية'] + $this->payload(), $keyOfB)->assertCreated();
        $this->reconcile($keyOfA)->assertJsonPath('data.cycle.code', 'cycle-2026')->assertJsonPath('data.title', 'مبادرة ذاكرة الحيّ');
        $this->reconcile($keyOfB)->assertJsonPath('data.cycle.code', 'cycle-2027')->assertJsonPath('data.title', 'عمل الدورة الثانية');
    }

    public function test_reconciliation_is_bound_to_the_account(): void
    {
        $this->configureCycle();
        $this->completeStage1($this->candidate);
        $other = User::factory()->candidate()->create(['email' => 'other@example.org']);
        $this->completeStage1($other);

        $this->signIn();
        $contextOfA = AccountContext::for($this->candidate);
        $keyOfA = $this->journeyKey();
        $this->confirm($this->payload(), $keyOfA)->assertCreated();

        // Another account signed in meanwhile: refused, nothing of either shown.
        $this->signIn($other->email);
        $response = $this->reconcile($keyOfA, $contextOfA)->assertConflict()->assertJsonPath('code', 'account_mismatch')->assertJsonMissingPath('data');
        $this->assertStringNotContainsString('1500', $response->getContent());
        // B's own context with A's journey key: not B's journey, and A's data never shown.
        $this->reconcile($keyOfA)->assertOk()->assertJsonPath('data', null)->assertJsonPath('journey.status', 'changed');

        // Without a confirmed Stage 1 there is no journey to reconcile.
        $third = User::factory()->candidate()->create(['email' => 'third@example.org']);
        $this->signIn($third->email);
        $this->reconcile($keyOfA)->assertConflict()->assertJsonPath('code', 'basic_information_required');
    }

    public function test_a_deadline_passing_after_the_preliminary_check_refuses_the_confirmation(): void
    {
        $this->readyCandidate();
        $journeyKey = $this->journeyKey();
        DB::table('cycle_stages')->update(['closes_at' => now()->addMinute()->format('Y-m-d H:i:s')]);

        // The gate (outside the transaction) still sees the stage open; the
        // deadline passes before the confirmation's locked check.
        $armed = true;
        Event::listen(TransactionBeginning::class, function () use (&$armed): void {
            if ($armed) {
                $armed = false;
                $this->travel(2)->minutes();
            }
        });

        $this->confirm($this->payload(), $journeyKey)->assertConflict()->assertJsonPath('code', 'nomination_unavailable');
        $this->assertFalse($armed, 'the deadline passed inside the confirmation');
        $this->assertNothingStored();
    }

    public function test_pages_and_forms_are_bound_to_the_displayed_account(): void
    {
        $this->configureCycle();
        $this->completeStage1($this->candidate);
        $other = User::factory()->candidate()->create(['email' => 'other@example.org']);
        $this->completeStage1($other);

        $this->signIn();
        $contextOfA = AccountContext::for($this->candidate);
        $keyOfA = $this->journeyKey();

        // Switched to B before the page loads, and with A's form open.
        $this->signIn($other->email);
        $this->read('/api/candidate/nomination-details', $contextOfA)->assertConflict()->assertJsonPath('code', 'account_mismatch')->assertJsonMissingPath('data');
        $this->send('/api/candidate/nomination-details', $this->payload() + ['account_context' => $contextOfA], $keyOfA)->assertConflict()->assertJsonPath('code', 'account_mismatch');
        // B's own context with A's journey key: still not B's journey.
        $this->send('/api/candidate/nomination-details', $this->payload(), $keyOfA)->assertConflict()->assertJsonPath('code', 'journey_changed');
        $this->assertNothingStored();

        // B confirms; A's form retried in B's session never receives B's data.
        $this->confirm($this->payload(), $this->journeyKey())->assertCreated();
        $response = $this->send('/api/candidate/nomination-details', $this->payload() + ['account_context' => $contextOfA], $keyOfA)->assertConflict();
        $this->assertSame('account_mismatch', $response->json('code'));
        $this->assertStringNotContainsString('1500', $response->getContent());

        // Signing back in as A resumes A's open form with its own key.
        $this->signIn();
        $this->confirm($this->payload(), $keyOfA)->assertCreated();
        $this->assertSame(2, DB::table('candidate_nominations')->count());
    }

    public function test_only_active_candidates_reach_the_stage(): void
    {
        $this->configureCycle();
        $this->startCsrfSession();
        $this->browser('GET', '/api/candidate/nomination-details')->assertUnauthorized();

        $this->signIn($this->operator->email);
        $this->browser('GET', '/api/candidate/nomination-details')->assertForbidden()->assertJsonPath('code', 'not_candidate');

        $this->completeStage1($this->candidate);
        $this->signIn();
        $this->browser('POST', '/api/candidate/nomination-details', $this->payload() + ['journey_key' => $this->journeyKey(), 'account_context' => AccountContext::for($this->candidate)], withCsrfHeader: false)->assertStatus(419);
        $this->candidate->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now()])->save();
        $this->read('/api/candidate/nomination-details')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->assertNothingStored();
    }

    public function test_the_setup_command_is_explicit_idempotent_and_preserves_records(): void
    {
        DB::table('award_domains')->insert(['code' => 'heritage', 'name_ar' => 'التراث المادي واللامادي', 'description' => 'existing', 'is_active' => true]);
        $existingDomain = (array) DB::table('award_domains')->where('code', 'heritage')->first();

        $this->artisan('nomination:setup', ['cycle' => 'cycle-2026', '--actor' => $this->operator->email])->assertFailed();
        $this->artisan('nomination:setup', ['cycle' => 'cycle-2026', '--actor' => $this->operator->email, '--create-cycle' => true, '--name' => 'x'])->assertFailed();
        $this->artisan('nomination:setup', ['cycle' => 'cycle-2026', '--create-cycle' => true])->assertExitCode(2);

        $full = ['cycle' => 'cycle-2026', '--actor' => $this->operator->email, '--create-cycle' => true, '--name' => 'الدورة الأولى', '--award-name' => 'جائزة',
            '--year' => '2026', '--timezone' => 'Asia/Damascus', '--country' => 'SY', '--open-domains' => true, '--enable' => true, '--publish-cycle' => true,
            '--opens-at' => '2026-01-01 09:00', '--closes-at' => '2099-12-31 23:00'];
        $this->artisan('nomination:setup', $full + ['--dry-run' => true])->expectsOutputToContain('Dry run: nothing was changed.')->assertSuccessful();
        $this->assertSame(0, DB::table('award_cycles')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('actor_user_id', $this->operator->id)->count());

        $this->artisan('nomination:setup', $full)->expectsOutputToContain('Stage 2 availability after these changes: available')->assertSuccessful();
        $this->assertSame([3, 3, 7, 1], [DB::table('award_domains')->count(), DB::table('cycle_domains')->count(), DB::table('cycle_domain_subcategories')->count(), DB::table('cycle_stages')->count()]);
        $this->assertSame($existingDomain, (array) DB::table('award_domains')->where('code', 'heritage')->first(), 'existing domain kept as it is');
        $stage = DB::table('cycle_stages')->sole();
        $this->assertSame(['enabled', $this->operator->id, '2026-01-01 06:00:00'], [$stage->status, (int) $stage->enabled_by, $stage->opens_at]);
        $this->assertSame(['published', ['implementation_country' => 'SY']], [DB::table('award_cycles')->value('status'), json_decode(DB::table('award_cycles')->value('eligibility_rules'), true)]);
        $this->assertGreaterThan(10, DB::table('audit_logs')->where('actor_user_id', $this->operator->id)->count());
        $this->assertSame(0, DB::table('application_versions')->count() + DB::table('award_slots')->count());

        // Rerunning changes nothing; the country is never overwritten.
        $before = [DB::table('cycle_domain_subcategories')->orderBy('id')->get()->toArray(), DB::table('audit_logs')->count()];
        Artisan::call('nomination:setup', array_diff_key($full, array_flip(['--opens-at', '--closes-at'])));
        $this->assertStringContainsString('Nothing to change.', Artisan::output());
        $this->assertEquals($before, [DB::table('cycle_domain_subcategories')->orderBy('id')->get()->toArray(), DB::table('audit_logs')->count()]);
        $this->artisan('nomination:setup', ['cycle' => 'cycle-2026', '--actor' => $this->operator->email, '--country' => 'IQ'])->assertFailed();
    }
}
