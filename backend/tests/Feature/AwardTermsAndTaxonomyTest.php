<?php

namespace Tests\Feature;

use App\Admin\AdminAccess;
use App\Candidates\AccountContext;
use App\Candidates\AwardTerms;
use App\Candidates\Documentation;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\ActsAsSpaBrowser;
use Tests\Feature\Candidate\InteractsWithNominationStage;
use Tests\TestCase;

class AwardTermsAndTaxonomyTest extends TestCase
{
    use ActsAsSpaBrowser, InteractsWithNominationStage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create();
        $this->candidate = User::factory()->candidate()->create();
    }

    public function test_complete_bilingual_conditions_have_six_categories_and_sixteen_specific_requirements(): void
    {
        $content = app(AwardTerms::class)->content();
        foreach (['ar', 'en'] as $locale) {
            $this->assertCount(5, $content[$locale]['general']);
            $categories = collect($content[$locale]['domains'])->flatMap(fn (array $domain): array => $domain['subcategories']);
            $this->assertCount(6, $categories);
            $this->assertSame(16, $categories->sum(fn (array $category): int => count($category['requirements'])));
        }
        $this->assertStringContainsString('جائزة من قبل', app(AwardTerms::class)->body('ar'));
        $this->assertStringContainsString('previously', app(AwardTerms::class)->body('en'));
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06ff}]/u', app(AwardTerms::class)->body('en'));
    }

    public function test_targeted_migration_is_idempotent_preserves_ids_and_requires_draft_reselection(): void
    {
        $this->readyCandidate();
        $this->confirm($this->payload())->assertCreated();
        $application = DB::table('applications')->sole();
        $category = DB::table('cycle_domain_subcategories')->insertGetId(['cycle_id' => $application->cycle_id, 'cycle_domain_id' => $application->cycle_domain_id, 'code' => 'quality', 'name_ar' => 'الجودة', 'is_active' => true]);
        DB::table('applications')->where('id', $application->id)->update(['subcategory_id' => $category]);
        $culture = DB::table('cycle_domain_subcategories')->where('code', 'poetry')->sole();
        DB::table('cycle_domain_subcategories')->where('id', $culture->id)->update(['code' => 'culture']);
        $migration = require database_path('migrations/2026_11_02_093121_update_award_categories_and_terms.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('cycle_domain_subcategories', ['id' => $culture->id, 'code' => 'poetry']);
        $this->assertSame(6, DB::table('cycle_domain_subcategories')->where('is_active', true)->count());
        $this->assertDatabaseHas('cycle_domain_subcategories', ['id' => $category, 'is_active' => false]);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'subcategory_id' => $category, 'taxonomy_review_required' => true]);
        $this->assertSame(1, DB::table('policy_versions')->where('code', AwardTerms::CODE)->count());
        $key = $this->journeyKey();
        $this->send('/api/candidate/category-correction', ['subcategory' => 'poetry'], $key)->assertUnprocessable();
        $this->send('/api/candidate/category-correction', ['subcategory' => 'entrepreneurship'], $key)->assertOk()->assertJsonPath('data.taxonomy_review_required', false);
        $this->assertDatabaseCount('candidate_nominations', 1);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'title' => $application->title, 'subcategory_id' => $this->subcategoryId('cycle-2026', 'entrepreneurship')]);
        DB::table('cycle_stages')->where('code', 'nomination_details')->update(['status' => 'closed']);
        DB::table('applications')->where('id', $application->id)->update(['taxonomy_review_required' => true]);
        $this->send('/api/candidate/category-correction', ['subcategory' => 'entrepreneurship'], $key)->assertConflict();
    }

    public function test_submission_requires_explicit_current_terms_and_preserves_the_accepted_version(): void
    {
        $this->readyToSubmit();
        $terms = $this->read('/api/candidate/review')->assertOk()->json('data.terms');
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0, 'accepted_terms' => false])->assertConflict()->assertJsonPath('code', 'terms_changed');
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0, 'terms_version_id' => 999])->assertConflict()->assertJsonPath('code', 'terms_changed');
        $this->assertDatabaseCount('application_versions', 0);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated();
        $version = DB::table('application_versions')->sole();
        $snapshot = json_decode($version->data_snapshot, true);
        $this->assertSame($terms['id'], $snapshot['application_terms']['policy_version_id']);
        $this->assertDatabaseHas('application_consents', ['application_version_id' => $version->id, 'policy_version_id' => $terms['id'], 'accepted_by' => $this->candidate->id]);
        $next = (array) DB::table('policy_versions')->where('id', $terms['id'])->first();
        unset($next['id']);
        $next['version_number']++;
        $next['body_ar'] = 'نسخة أحدث';
        $next['content_hash'] = hash('sha256', $next['body_ar']);
        DB::table('policy_versions')->insert($next);
        $this->read('/api/candidate/review')->assertOk()->assertJsonPath('data.terms.id', $terms['id'])->assertJsonPath('data.terms.body_ar', $terms['body_ar']);
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0, 'accepted_terms' => false])->assertOk()->assertJsonPath('code', 'already_submitted');
        $this->assertSame($version->content_hash, DB::table('application_versions')->value('content_hash'));
    }

    public function test_submitted_category_needs_admin_review_without_rewriting_immutable_snapshot(): void
    {
        $this->readyToSubmit();
        $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated();
        $application = DB::table('applications')->sole();
        $version = DB::table('application_versions')->sole();
        DB::table('applications')->where('id', $application->id)->update(['taxonomy_review_required' => true]);
        $this->send('/api/candidate/category-correction', ['subcategory' => 'social_responsibility'])->assertConflict();
        app(AdminAccess::class)->grantAdmin($this->operator);
        $this->actingAs($this->operator);
        $this->postJson('/api/admin/applications/'.$application->id.'/category-correction', ['account_context' => AccountContext::for($this->operator), 'subcategory' => 'social_responsibility'])->assertOk()->assertJsonPath('data.current_category.review_required', false);
        $this->assertSame($version->data_snapshot, DB::table('application_versions')->value('data_snapshot'));
        $this->assertSame($version->content_hash, DB::table('application_versions')->value('content_hash'));
    }

    private function readyToSubmit(): void
    {
        $this->readyCandidate();
        $this->confirm($this->payload())->assertCreated();
        $applicantId = (int) DB::table('candidate_profiles')->where('user_id', $this->candidate->id)->value('applicant_id');
        (new ApplicantIndividualDetail)->forceFill(['applicant_id' => $applicantId, 'first_name' => 'ليلى', 'last_name' => 'حسن', 'birth_date' => '1990-04-02', 'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => '04019876543'])->save();
        $application = DB::table('applications')->sole();
        DB::table('candidate_nominations')->update(['documentation_confirmed_revision' => 0, 'documentation_confirmed_at' => now()]);
        $file = DB::table('files')->insertGetId(['uploaded_by' => $this->candidate->id, 'storage_disk' => 'candidate_evidence', 'storage_path' => 'terms-test.pdf', 'original_name' => 'work.pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'scan_status' => 'clean', 'scanned_at' => now(), 'access_level' => 'private']);
        DB::table('file_links')->insert(['file_id' => $file, 'application_id' => $application->id, 'purpose' => Documentation::KINDS['explanatory']['purpose']]);
    }
}
