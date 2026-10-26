<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * Exercises the database-enforced rules of the platform schema on MySQL.
 * Each test runs inside a transaction that is rolled back afterwards.
 */
class PlatformDatabaseConstraintTest extends TestCase
{
    use UsesDisposableSchemaDatabase;

    private const DUPLICATE = 1062;

    private const PARENT_IN_USE = 1451;

    private const MISSING_PARENT = 1452;

    private const CHECK_FAILED = 3819;

    private const NOW = '2026-11-01 10:00:00';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDisposableDatabase();
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && config()->has('database.connections.'.self::SCHEMA_CONNECTION) && $this->schemaDb()->transactionLevel() > 0) {
            $this->schemaDb()->rollBack();
        }

        parent::tearDown();
    }

    public function test_duplicate_cycle_domains_and_version_numbers_are_rejected(): void
    {
        $cycle = $this->cycle();
        $domain = $this->domain();
        $this->cycleDomain($cycle, $domain);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->cycleDomain($cycle, $domain), 'cycle domain pair');

        $cycleDomain = $this->cycleDomain($cycle, $this->domain());
        $application = $this->application($cycleDomain);
        $this->version($application, 1);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->version($application, 1), 'application version number');

        $this->rubric($cycleDomain, 1);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->rubric($cycleDomain, 1), 'rubric version number');

        $this->policy($cycle, 'publication', 1);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->policy($cycle, 'publication', 1), 'policy version number');

        $this->slot($cycleDomain, 1);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->slot($cycleDomain, 1), 'slot number within a domain');
    }

    public function test_duplicate_assignments_evaluations_and_scores_are_rejected(): void
    {
        $judging = $this->judgingSetup();
        $assignment = $this->assignment($judging);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->assignment($judging), 'same version, member and round');
        $this->assignment($judging, round: 2);

        $evaluation = $this->evaluation($assignment, $judging['rubric']);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->evaluation($assignment, $judging['rubric']), 'second evaluation');

        $this->score($evaluation, $judging, 8);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->score($evaluation, $judging, 9), 'second score for a criterion');
    }

    public function test_foreign_keys_reject_missing_parents(): void
    {
        $cycleDomain = $this->cycleDomain($this->cycle(), $this->domain());

        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->application($cycleDomain, applicant: 999999), 'missing applicant');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('file_links')->insert([
            'file_id' => 999999, 'application_id' => $this->application($cycleDomain), 'purpose' => 'evidence',
        ]), 'missing file');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('applications')->insert([
            'cycle_id' => $this->cycle(), 'cycle_domain_id' => $cycleDomain, 'applicant_id' => $this->applicant(), 'created_by' => $this->user(),
        ]), 'cycle domain of another cycle');
    }

    public function test_file_links_need_exactly_one_target_and_no_duplicates(): void
    {
        $cycleDomain = $this->cycleDomain($this->cycle(), $this->domain());
        $application = $this->application($cycleDomain);
        $version = $this->version($application, 1);
        $file = $this->file();

        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('file_links')->insert([
            'file_id' => $file, 'purpose' => 'evidence',
        ]), 'no target');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('file_links')->insert([
            'file_id' => $file, 'application_id' => $application, 'application_version_id' => $version, 'purpose' => 'evidence',
        ]), 'two targets');

        $this->db('file_links')->insert(['file_id' => $file, 'application_version_id' => $version, 'purpose' => 'evidence']);
        $this->db('file_links')->insert(['file_id' => $file, 'application_id' => $application, 'purpose' => 'evidence']);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->db('file_links')->insert([
            'file_id' => $file, 'application_version_id' => $version, 'purpose' => 'photo',
        ]), 'same file linked twice to one version');
    }

    public function test_results_keep_grant_and_withholding_consistent(): void
    {
        $judging = $this->judgingSetup();
        $slot = $this->slot($judging['cycle_domain'], 1);

        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->awardResult($judging, $slot, 1, 'grant', withVersion: false), 'grant without a version');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->awardResult($judging, $slot, 1, 'withhold', withVersion: true), 'withholding with a version');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->awardResult($judging, $slot, 1, 'awarded', withVersion: true), 'unknown decision type');

        $this->awardResult($judging, $slot, 1, 'grant', withVersion: true);
        $this->awardResult($judging, $this->slot($judging['cycle_domain'], 2), 1, 'withhold', withVersion: false);

        $otherDomain = $this->cycleDomain($judging['cycle'], $this->domain());
        $foreignVersion = $this->version($this->application($otherDomain), 1);
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->awardResult(
            $judging, $this->slot($judging['cycle_domain'], 3), 1, 'grant', withVersion: true, version: $foreignVersion,
        ), 'granting a slot to an application of another domain');
    }

    public function test_same_application_version_relationships_are_enforced(): void
    {
        $cycleDomain = $this->cycleDomain($this->cycle(), $this->domain());
        $application = $this->application($cycleDomain);
        $other = $this->application($cycleDomain);
        $version = $this->version($application, 1);
        $otherVersion = $this->version($other, 1);

        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('applications')->where('id', $application)->update([
            'current_version_id' => $otherVersion, 'status' => 'submitted', 'first_submitted_at' => self::NOW,
        ]), 'current version of another application');
        $this->db('applications')->where('id', $application)->update([
            'current_version_id' => $version, 'status' => 'submitted', 'first_submitted_at' => self::NOW,
        ]);
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('applications')->where('id', $other)->update([
            'status' => 'submitted',
        ]), 'submitted without a current version');

        $request = $this->db('completion_requests')->insertGetId([
            'application_id' => $application, 'based_on_version_id' => $version, 'requested_by' => $this->user(), 'message' => 'Missing evidence',
        ]);
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('completion_requests')->insert([
            'application_id' => $application, 'based_on_version_id' => $otherVersion, 'requested_by' => $this->user(), 'message' => 'x',
        ]), 'source version of another application');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('completion_requests')->where('id', $request)->update([
            'status' => 'answered', 'response_version_id' => $otherVersion, 'closed_at' => self::NOW,
        ]), 'response version of another application');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->version($other, 2, completionRequest: $request), 'version answering another application\'s request');

        $response = $this->version($application, 2, completionRequest: $request);
        $this->db('completion_requests')->where('id', $request)->update([
            'status' => 'answered', 'response_version_id' => $response, 'closed_at' => self::NOW,
        ]);
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('completion_requests')->where('id', $request)->update([
            'status' => 'open',
        ]), 'open request with a closed timestamp and response');
    }

    public function test_only_one_current_result_per_award_slot(): void
    {
        $judging = $this->judgingSetup();
        $slot = $this->slot($judging['cycle_domain'], 1);
        $first = $this->awardResult($judging, $slot, 1, 'withhold', withVersion: false);

        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->awardResult($judging, $slot, 2, 'grant', withVersion: true, supersedes: $first), 'second current result');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->awardResult($judging, $slot, 2, 'grant', withVersion: true), 'later version that supersedes nothing');

        $this->db('award_results')->where('id', $first)->update(['status' => 'superseded']);
        $second = $this->awardResult($judging, $slot, 2, 'grant', withVersion: true, supersedes: $first);
        $this->assertSame(2, $this->db('award_results')->where('award_slot_id', $slot)->count(), 'superseded history is kept');
        $this->assertSame($second, $this->db('award_results')->where('award_slot_id', $slot)->where('is_current', 1)->value('id'));

        $this->db('award_results')->where('id', $second)->update(['status' => 'superseded']);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->awardResult($judging, $slot, 3, 'withhold', withVersion: false, supersedes: $first), 'superseding the same result twice');

        $otherSlot = $this->slot($judging['cycle_domain'], 2);
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->awardResult($judging, $otherSlot, 2, 'withhold', withVersion: false, supersedes: $second), 'superseding a result of another slot');
    }

    public function test_restrictive_deletion_protects_history(): void
    {
        $judging = $this->judgingSetup();
        $policy = $this->policy($judging['cycle'], 'truthful_data', 1);
        $consent = $this->db('application_consents')->insertGetId([
            'application_version_id' => $judging['version'], 'policy_version_id' => $policy,
            'accepted_by' => $judging['user'], 'accepted_at' => self::NOW,
        ]);
        $assignment = $this->assignment($judging);
        $evaluation = $this->evaluation($assignment, $judging['rubric']);
        $this->score($evaluation, $judging, 7);
        $this->db('audit_logs')->insert([
            'action' => 'application.submitted', 'subject_type' => 'application', 'subject_id' => $judging['application'], 'occurred_at' => self::NOW,
        ]);

        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('applications')->where('id', $judging['application'])->delete(), 'application with versions');
        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('application_versions')->where('id', $judging['version'])->delete(), 'version with consents and judging');
        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('policy_versions')->where('id', $policy)->delete(), 'accepted policy text');
        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('judging_assignments')->where('id', $assignment)->delete(), 'assignment with an evaluation');
        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('evaluations')->where('id', $evaluation)->delete(), 'evaluation with scores');
        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('evaluation_rubrics')->where('id', $judging['rubric'])->delete(), 'rubric in use (criteria would cascade into scores)');
        $this->assertDatabaseRejects(self::PARENT_IN_USE, fn () => $this->db('users')->where('id', $judging['user'])->delete(), 'user who submitted and judged');
        $this->assertSame(1, $this->db('application_consents')->where('id', $consent)->count());
        $this->assertSame(1, $this->db('audit_logs')->where('subject_id', $judging['application'])->count());
    }

    public function test_scores_weights_and_criteria_must_be_valid(): void
    {
        $judging = $this->judgingSetup();
        $evaluation = $this->evaluation($this->assignment($judging), $judging['rubric']);

        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->score($evaluation, $judging, -1), 'negative score');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->score($evaluation, $judging, 10.01), 'score above the criterion maximum');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->score($evaluation, $judging, 5, maxScore: 100), 'forged criterion maximum');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('evaluation_scores')->insert([
            'evaluation_id' => $evaluation, 'rubric_id' => $judging['rubric'], 'criterion_id' => $judging['criterion'],
            'criterion_max_score' => 10, 'score' => 4, 'is_not_applicable' => true, 'not_applicable_reason' => 'n/a',
        ]), 'not-applicable criterion with a score');
        $this->score($evaluation, $judging, 10);

        $otherRubric = $this->rubric($judging['cycle_domain'], 2);
        $otherCriterion = $this->criterion($otherRubric);
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('evaluation_scores')->insert([
            'evaluation_id' => $evaluation, 'rubric_id' => $judging['rubric'], 'criterion_id' => $otherCriterion, 'criterion_max_score' => 10, 'score' => 1,
        ]), 'criterion of another rubric');

        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->criterion($otherRubric, weight: -0.5), 'negative weight');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->criterion($otherRubric, weight: 100.5), 'weight above 100%');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->criterion($otherRubric, maxScore: 0), 'non-positive maximum');
        $this->criterion($otherRubric, weight: null);
    }

    public function test_judging_assignments_stay_inside_one_cycle_domain(): void
    {
        $judging = $this->judgingSetup();
        $otherDomain = $this->cycleDomain($judging['cycle'], $this->domain());
        $otherCommittee = $this->committee($judging['cycle'], 'judging', $otherDomain);
        $outsider = $this->member($otherCommittee);

        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->assignment($judging, rubric: $this->rubric($otherDomain, 1)), 'rubric of another domain');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->assignment($judging, member: $outsider), 'member of another committee');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->assignment($judging, member: $outsider, committee: $otherCommittee), 'committee of another domain');
    }

    public function test_role_scopes_and_effective_grants(): void
    {
        $platformRole = $this->role('platform');
        $cycleRole = $this->role('cycle');
        $user = $this->user();
        $cycle = $this->cycle();

        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('user_roles')->insert([
            'user_id' => $user, 'role_id' => $cycleRole, 'assigned_at' => self::NOW,
        ]), 'cycle role granted globally');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('cycle_user_roles')->insert([
            'cycle_id' => $cycle, 'user_id' => $user, 'role_id' => $platformRole, 'starts_at' => self::NOW,
        ]), 'global role granted in a cycle');

        $grant = $this->db('user_roles')->insertGetId(['user_id' => $user, 'role_id' => $platformRole, 'assigned_at' => self::NOW]);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->db('user_roles')->insert([
            'user_id' => $user, 'role_id' => $platformRole, 'assigned_at' => self::NOW,
        ]), 'duplicate effective grant');
        $this->db('user_roles')->where('id', $grant)->update(['revoked_at' => self::NOW, 'revoked_by' => $user]);
        $this->db('user_roles')->insert(['user_id' => $user, 'role_id' => $platformRole, 'assigned_at' => self::NOW]);
        $this->assertSame(2, $this->db('user_roles')->where('user_id', $user)->count(), 'revoked grant kept as history');

        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('users')->where('id', $user)->update(['status' => 'disabled']), 'disabled without disabled_at');
    }

    public function test_committee_type_scope_and_uniqueness(): void
    {
        $cycle = $this->cycle();
        $cycleDomain = $this->cycleDomain($cycle, $this->domain());

        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->committee($cycle, 'judging', null), 'judging committee without a domain');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->committee($cycle, 'integrity', $cycleDomain), 'cycle-wide committee with a domain');
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->committee($this->cycle(), 'judging', $cycleDomain), 'domain of another cycle');

        $first = $this->committee($cycle, 'judging', $cycleDomain);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->committee($cycle, 'judging', $cycleDomain), 'second current judging committee');
        $integrity = $this->committee($cycle, 'integrity', null);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->committee($cycle, 'integrity', null), 'second current integrity committee');
        $this->db('committees')->where('id', $first)->update(['status' => 'dissolved']);
        $this->committee($cycle, 'judging', $cycleDomain);

        $member = $this->member($integrity);
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->db('committee_members')->insert([
            'committee_id' => $integrity, 'user_id' => $this->db('committee_members')->where('id', $member)->value('user_id'), 'joined_at' => self::NOW,
        ]), 'duplicate effective membership');

        $appeal = ['cycle_id' => $cycle, 'submitted_by' => $this->user(), 'type' => 'procedural_complaint', 'subject' => 'S', 'description' => 'D', 'submitted_at' => self::NOW];
        $this->db('appeals')->insert($appeal + ['committee_id' => $integrity]);
        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('appeals')->insert($appeal + ['committee_id' => $first]), 'appeal assigned to a judging committee');
        $this->assertDatabaseRejects(self::CHECK_FAILED, fn () => $this->db('appeals')->insert($appeal + ['challenged_record_type' => 'App\\Models\\User', 'challenged_record_id' => 1]), 'class name as challenged record type');
    }

    public function test_publication_consent_must_belong_to_the_published_version(): void
    {
        $cycle = $this->cycle();
        $cycleDomain = $this->cycleDomain($cycle, $this->domain());
        $version = $this->version($this->application($cycleDomain), 1);
        $otherVersion = $this->version($this->application($cycleDomain), 1);
        $policy = $this->policy($cycle, 'publication', 1);
        $consent = $this->db('application_consents')->insertGetId([
            'application_version_id' => $otherVersion, 'policy_version_id' => $policy, 'accepted_by' => $this->user(), 'accepted_at' => self::NOW,
        ]);

        $this->assertDatabaseRejects(self::MISSING_PARENT, fn () => $this->db('published_stories')->insert([
            'application_version_id' => $version, 'publication_consent_id' => $consent, 'public_slug' => 'story-'.$this->next(),
            'public_title' => 'T', 'public_applicant_name' => 'N',
        ]), 'consent given for a different version');
        $this->assertDatabaseRejects(self::DUPLICATE, fn () => $this->db('application_consents')->insert([
            'application_version_id' => $otherVersion, 'policy_version_id' => $policy, 'accepted_by' => $this->user(), 'accepted_at' => self::NOW,
        ]), 'duplicate acceptance');
    }

    private function db(string $table): Builder
    {
        return $this->schemaDb()->table($table);
    }

    private function next(): int
    {
        return ++$this->sequence;
    }

    private function user(): int
    {
        $n = $this->next();

        return $this->db('users')->insertGetId([
            'name' => "User {$n}", 'email' => "user{$n}.".uniqid().'@example.org', 'password' => 'not-a-real-hash',
        ]);
    }

    private function role(string $scope): int
    {
        return $this->db('roles')->insertGetId(['code' => "role_{$scope}_".$this->next().uniqid(), 'name_ar' => 'دور', 'scope' => $scope]);
    }

    private function cycle(): int
    {
        return $this->db('award_cycles')->insertGetId([
            'code' => 'C'.$this->next().substr(uniqid(), -6), 'name_ar' => 'الدورة', 'award_name_ar' => 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع',
            'year' => 2026, 'timezone' => 'Asia/Damascus',
        ]);
    }

    private function domain(): int
    {
        return $this->db('award_domains')->insertGetId(['code' => 'domain_'.$this->next().uniqid(), 'name_ar' => 'محور']);
    }

    private function cycleDomain(int $cycle, int $domain): int
    {
        return $this->db('cycle_domains')->insertGetId(['cycle_id' => $cycle, 'domain_id' => $domain, 'name_ar' => 'محور']);
    }

    private function slot(int $cycleDomain, int $number): int
    {
        return $this->db('award_slots')->insertGetId([
            'cycle_domain_id' => $cycleDomain, 'code' => 'slot_'.$this->next(), 'name_ar' => 'جائزة', 'slot_number' => $number,
        ]);
    }

    private function applicant(): int
    {
        return $this->db('applicants')->insertGetId(['type' => 'initiative', 'name_ar' => 'مبادرة', 'representative_user_id' => $this->user()]);
    }

    private function application(int $cycleDomain, ?int $applicant = null): int
    {
        return $this->db('applications')->insertGetId([
            'cycle_id' => $this->db('cycle_domains')->where('id', $cycleDomain)->value('cycle_id'),
            'cycle_domain_id' => $cycleDomain,
            'applicant_id' => $applicant ?? $this->applicant(),
            'created_by' => $this->user(),
        ]);
    }

    private function version(int $application, int $number, ?int $completionRequest = null): int
    {
        return $this->db('application_versions')->insertGetId([
            'application_id' => $application,
            'version_number' => $number,
            'submission_type' => $completionRequest === null ? 'initial' : 'completion_response',
            'completion_request_id' => $completionRequest,
            'submitted_by' => $this->user(),
            'submitted_at' => self::NOW,
            'data_snapshot' => '{}',
            'form_snapshot' => '{}',
            'content_hash' => hash('sha256', (string) $this->next()),
        ]);
    }

    private function policy(int $cycle, string $code, int $number): int
    {
        return $this->db('policy_versions')->insertGetId([
            'cycle_id' => $cycle, 'code' => $code, 'version_number' => $number, 'title_ar' => 'سياسة', 'body_ar' => 'نص', 'status' => 'published', 'published_at' => self::NOW,
        ]);
    }

    private function file(): int
    {
        $n = $this->next();

        return $this->db('files')->insertGetId([
            'storage_disk' => 'private', 'storage_path' => "evidence/{$n}-".uniqid().'.pdf', 'original_name' => 'evidence.pdf',
            'detected_mime_type' => 'application/pdf', 'size_bytes' => 1024, 'sha256' => hash('sha256', 'file'),
        ]);
    }

    private function committee(int $cycle, string $type, ?int $cycleDomain): int
    {
        return $this->db('committees')->insertGetId(['cycle_id' => $cycle, 'cycle_domain_id' => $cycleDomain, 'type' => $type, 'name_ar' => 'لجنة']);
    }

    private function member(int $committee): int
    {
        return $this->db('committee_members')->insertGetId(['committee_id' => $committee, 'user_id' => $this->user(), 'joined_at' => self::NOW]);
    }

    private function rubric(int $cycleDomain, int $number): int
    {
        return $this->db('evaluation_rubrics')->insertGetId(['cycle_domain_id' => $cycleDomain, 'version_number' => $number, 'name_ar' => 'مصفوفة']);
    }

    private function criterion(int $rubric, ?float $weight = 50, float $maxScore = 10): int
    {
        return $this->db('evaluation_criteria')->insertGetId([
            'rubric_id' => $rubric, 'code' => 'criterion_'.$this->next(), 'name_ar' => 'معيار', 'weight' => $weight, 'max_score' => $maxScore,
        ]);
    }

    /**
     * @return array{cycle: int, cycle_domain: int, application: int, version: int, committee: int, member: int, rubric: int, criterion: int, user: int}
     */
    private function judgingSetup(): array
    {
        $cycle = $this->cycle();
        $cycleDomain = $this->cycleDomain($cycle, $this->domain());
        $application = $this->application($cycleDomain);
        $committee = $this->committee($cycle, 'judging', $cycleDomain);
        $rubric = $this->rubric($cycleDomain, 1);

        return [
            'cycle' => $cycle,
            'cycle_domain' => $cycleDomain,
            'application' => $application,
            'version' => $this->version($application, 1),
            'committee' => $committee,
            'member' => $this->member($committee),
            'rubric' => $rubric,
            'criterion' => $this->criterion($rubric),
            'user' => (int) $this->db('applications')->where('id', $application)->value('created_by'),
        ];
    }

    /**
     * @param  array{cycle: int, cycle_domain: int, application: int, version: int, committee: int, member: int, rubric: int, criterion: int, user: int}  $judging
     */
    private function assignment(array $judging, int $round = 1, ?int $rubric = null, ?int $member = null, ?int $committee = null): int
    {
        return $this->db('judging_assignments')->insertGetId([
            'application_version_id' => $judging['version'],
            'application_id' => $judging['application'],
            'cycle_domain_id' => $judging['cycle_domain'],
            'committee_id' => $committee ?? $judging['committee'],
            'committee_member_id' => $member ?? $judging['member'],
            'rubric_id' => $rubric ?? $judging['rubric'],
            'round_number' => $round,
            'assigned_by' => $judging['user'],
            'assigned_at' => self::NOW,
        ]);
    }

    private function evaluation(int $assignment, int $rubric): int
    {
        return $this->db('evaluations')->insertGetId(['judging_assignment_id' => $assignment, 'rubric_id' => $rubric]);
    }

    /**
     * @param  array{cycle: int, cycle_domain: int, application: int, version: int, committee: int, member: int, rubric: int, criterion: int, user: int}  $judging
     */
    private function score(int $evaluation, array $judging, float $score, float $maxScore = 10): int
    {
        return $this->db('evaluation_scores')->insertGetId([
            'evaluation_id' => $evaluation, 'rubric_id' => $judging['rubric'], 'criterion_id' => $judging['criterion'],
            'criterion_max_score' => $maxScore, 'score' => $score,
        ]);
    }

    /**
     * @param  array{cycle: int, cycle_domain: int, application: int, version: int, committee: int, member: int, rubric: int, criterion: int, user: int}  $judging
     */
    private function awardResult(array $judging, int $slot, int $decisionVersion, string $type, bool $withVersion, ?int $supersedes = null, ?int $version = null): int
    {
        $version ??= $judging['version'];

        return $this->db('award_results')->insertGetId([
            'cycle_id' => $judging['cycle'],
            'cycle_domain_id' => $judging['cycle_domain'],
            'award_slot_id' => $slot,
            'application_id' => $withVersion ? $this->db('application_versions')->where('id', $version)->value('application_id') : null,
            'application_version_id' => $withVersion ? $version : null,
            'decision_version' => $decisionVersion,
            'decision_type' => $type,
            'recommending_committee_id' => $judging['committee'],
            'technical_reason' => 'Committee reasoning',
            'supersedes_result_id' => $supersedes,
            'recommended_at' => self::NOW,
        ]);
    }
}
