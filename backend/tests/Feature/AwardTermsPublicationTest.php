<?php

namespace Tests\Feature;

use App\Candidates\AccountContext;
use App\Candidates\AwardTerms;
use App\Candidates\Documentation;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ActsAsSpaBrowser;
use Tests\Feature\Candidate\InteractsWithNominationStage;
use Tests\TestCase;

class AwardTermsPublicationTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'terms-operator@example.org']);
        $this->candidate = User::factory()->candidate()->create();
    }

    public function test_new_cycle_and_review_use_the_complete_shared_bilingual_source(): void
    {
        $this->readyDraft($this->candidate);
        $terms = app(AwardTerms::class);
        $this->read('/api/candidate/review')->assertOk()
            ->assertJsonPath('data.terms.body_ar', $terms->body('ar'))
            ->assertJsonPath('data.terms.body_en', $terms->body('en'))
            ->assertJsonPath('data.terms.content_hash', hash('sha256', $terms->body('ar')."\n".$terms->body('en')));
        foreach ($terms->content()['ar']['domains'] as $domain) {
            foreach ($domain['subcategories'] as $category) {
                $this->assertDatabaseHas('cycle_domain_subcategories', ['code' => $category['code'], 'name_ar' => $category['name']]);
            }
        }
    }

    public static function priorRevisions(): array
    {
        return ['original terms' => ['2026-11-02'], 'document-only anonymity' => ['2026-11-10']];
    }

    #[DataProvider('priorRevisions')]
    public function test_targeted_publication_preserves_history_and_requires_fresh_explicit_consent(string $revision): void
    {
        $this->legacyCycle($revision);
        $original = $this->candidate;
        $this->readyDraft($original);
        $old = $this->read('/api/candidate/review')->assertOk()->json('data.terms');
        if ($revision === '2026-11-10') {
            $this->assertStringContainsString('إخفاء الهوية داخل الوثائق', $old['body_ar']);
            $this->assertStringContainsString('Anonymity within the documents', $old['body_en']);
        }
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated();
        $oldVersion = DB::table('application_versions')->sole();
        $oldConsent = DB::table('application_consents')->sole();
        $oldPolicy = DB::table('policy_versions')->where('id', $old['id'])->first();
        $draft = User::factory()->candidate()->create();
        $this->readyDraft($draft);
        $this->read('/api/candidate/review')->assertJsonPath('data.terms.id', $old['id']);
        $protected = fn (): array => collect(['applications', 'candidate_nominations', 'application_versions', 'application_consents', 'files', 'file_links', 'user_roles', 'cycle_user_roles', 'judging_assignments', 'evaluations'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all();
        $before = $protected();
        $beforePolicies = DB::table('policy_versions')->get()->all();
        $beforeAudit = DB::table('audit_logs')->count();
        $options = ['cycle' => 'cycle-2026', '--actor' => $this->operator->email];
        $this->artisan('award-terms:publish', $options + ['--dry-run' => true])->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertEquals($beforePolicies, DB::table('policy_versions')->get()->all());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->artisan('award-terms:publish', $options)->expectsOutputToContain('Version: 2;')->assertSuccessful();
        $this->assertEquals($before, $protected());
        $current = app(AwardTerms::class)->current((int) $old['cycle_id']);
        $this->assertNotSame((int) $old['id'], (int) $current->id);
        $this->assertSame(app(AwardTerms::class)->body('ar'), $current->body_ar);
        $this->assertSame(app(AwardTerms::class)->body('en'), $current->body_en);
        $this->assertStringContainsString('يجب إخفاء هوية المترشح من النصوص والوثائق المقدّمة للتحكيم، وإزالة اسمه وتوقيعه وأي بيانات أو إشارات تدلّ على صاحبها.', $current->body_ar);
        $this->assertStringContainsString('The candidate’s identity must be concealed in the texts and documents submitted for judging. Their name, signature, and any data or indications that identify their author must be removed.', $current->body_en);
        $this->assertSame(hash('sha256', $current->body_ar."\n".$current->body_en), $current->content_hash);
        $this->read('/api/candidate/review')->assertJsonPath('data.terms.id', (int) $current->id)->assertJsonPath('data.terms.body_en', $current->body_en);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0, 'terms_version_id' => $old['id'], 'accepted_terms' => true])->assertConflict()->assertJsonPath('code', 'terms_changed');
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0, 'terms_version_id' => $current->id, 'accepted_terms' => false])->assertConflict()->assertJsonPath('code', 'terms_changed');
        $this->browser('POST', '/api/candidate/submission', ['reviewed_revision' => 0, 'terms_version_id' => $current->id, 'account_context' => AccountContext::for($draft), 'journey_key' => $this->journeyKey()])->assertConflict()->assertJsonPath('code', 'terms_changed');
        $this->assertEquals($before, $protected(), 'a rejected stale or missing consent never changes the draft');
        $audit = DB::table('audit_logs')->count();
        $this->artisan('award-terms:publish', $options)->expectsOutputToContain('Already current')->assertSuccessful();
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('policy_versions', 2);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0, 'terms_version_id' => $current->id, 'accepted_terms' => true])->assertCreated();
        $this->assertDatabaseHas('application_consents', ['policy_version_id' => $current->id, 'accepted_by' => $draft->id]);
        $this->assertEquals($oldPolicy, DB::table('policy_versions')->where('id', $old['id'])->first());
        $this->assertEquals($oldVersion, DB::table('application_versions')->where('id', $oldVersion->id)->first());
        $this->assertEquals($oldConsent, DB::table('application_consents')->where('id', $oldConsent->id)->first());
        $this->signIn($original->email);
        $this->read('/api/candidate/review')->assertJsonPath('data.terms.id', $old['id'])->assertJsonPath('data.terms.body_ar', $old['body_ar'])->assertJsonPath('data.terms.body_en', $old['body_en']);
    }

    public function test_other_cycles_and_closed_cycles_are_untouched_and_a_later_draft_is_not_republished(): void
    {
        $this->legacyCycle();
        $other = DB::table('award_cycles')->insertGetId(['code' => 'other-cycle', 'name_ar' => 'Other', 'award_name_ar' => 'Test award', 'year' => 2025, 'timezone' => 'Asia/Damascus', 'status' => 'draft']);
        $otherPolicy = $this->legacyTerms()->publishForCycle($other);
        DB::table('award_cycles')->where('id', $other)->update(['status' => 'closed', 'published_at' => now()]);
        $closed = DB::table('policy_versions')->where('id', $otherPolicy)->first();
        $this->artisan('award-terms:publish', ['cycle' => 'other-cycle', '--actor' => $this->operator->email])->assertExitCode(2);
        $options = ['cycle' => 'cycle-2026', '--actor' => $this->operator->email];
        $this->artisan('award-terms:publish', $options)->assertSuccessful();
        $cycle = (int) DB::table('award_cycles')->where('code', 'cycle-2026')->value('id');
        $current = app(AwardTerms::class)->current($cycle);
        DB::table('policy_versions')->insert(['cycle_id' => $cycle, 'code' => AwardTerms::CODE, 'version_number' => 3, 'title_ar' => 'Draft only', 'body_ar' => 'Unapproved draft', 'status' => 'draft']);
        $policies = DB::table('policy_versions')->orderBy('id')->get()->all();
        $this->artisan('award-terms:publish', $options)->expectsOutputToContain('Already current')->assertSuccessful();
        $this->assertSame((int) $current->id, app(AwardTerms::class)->publishForCycle($cycle));
        $this->assertEquals($policies, DB::table('policy_versions')->orderBy('id')->get()->all());
        $this->assertEquals($closed, DB::table('policy_versions')->where('id', $otherPolicy)->first());
        $this->artisan('award-terms:publish', ['cycle' => 'missing', '--actor' => $this->operator->email])->assertExitCode(2);
    }

    private function legacyTerms(string $revision = '2026-11-02'): AwardTerms
    {
        return new class($revision) extends AwardTerms
        {
            public function __construct(private string $revision) {}

            public function content(): array
            {
                return json_decode(file_get_contents(resource_path("award/terms-{$this->revision}.json")), true, flags: JSON_THROW_ON_ERROR);
            }
        };
    }

    private function legacyCycle(string $revision = '2026-11-02'): void
    {
        $this->app->instance(AwardTerms::class, $this->legacyTerms($revision));
        $this->configureCycle();
        $this->app->forgetInstance(AwardTerms::class);
        DB::table('cycle_domain_subcategories')->where('code', 'poetry')->update(['name_ar' => 'الشعر']);
        DB::table('cycle_domain_subcategories')->where('code', 'story')->update(['name_ar' => 'القصة']);
    }

    private function readyDraft(User $user): void
    {
        if (! DB::table('award_cycles')->exists()) {
            $this->configureCycle();
        }
        $this->completeStage1($user);
        $this->signIn($user->email);
        $this->confirm($this->payload())->assertCreated();
        $profile = DB::table('candidate_profiles')->where('user_id', $user->id)->first();
        (new ApplicantIndividualDetail)->forceFill(['applicant_id' => $profile->applicant_id, 'first_name' => 'Test', 'last_name' => 'Candidate', 'birth_date' => '1990-04-02', 'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => '04019876543'])->save();
        $application = DB::table('applications')->where('created_by', $user->id)->sole();
        DB::table('candidate_nominations')->where('user_id', $user->id)->update(['documentation_confirmed_revision' => 0, 'documentation_confirmed_at' => now()]);
        $file = DB::table('files')->insertGetId(['uploaded_by' => $user->id, 'storage_disk' => 'candidate_evidence', 'storage_path' => 'terms-'.$user->id.'.pdf', 'original_name' => 'work.pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'scan_status' => 'clean', 'scanned_at' => now(), 'access_level' => 'private']);
        DB::table('file_links')->insert(['file_id' => $file, 'application_id' => $application->id, 'purpose' => Documentation::KINDS['explanatory']['purpose']]);
    }
}
