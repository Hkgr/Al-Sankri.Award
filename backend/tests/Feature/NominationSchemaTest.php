<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use RuntimeException;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * The Stage 2 migrations (400001–400003) on a disposable MySQL or MariaDB
 * database: applying them on a deployed schema without touching existing
 * applications, refusing a rollback that would lose Stage 2 data, and the
 * constraints that keep subcategories inside their domain and cycle and
 * funding declarations consistent.
 */
class NominationSchemaTest extends TestCase
{
    use UsesDisposableSchemaDatabase;

    private const NOW = '2026-11-01 10:00:00';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDisposableDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && config()->has('database.connections.'.self::SCHEMA_CONNECTION) && $this->schemaDb()->transactionLevel() > 0) {
            $this->schemaDb()->rollBack();
        }

        parent::tearDown();
    }

    public function test_the_stage_2_migrations_apply_on_a_deployed_schema_and_roll_back_while_empty(): void
    {
        $this->deployedSchema();
        $cycle = $this->cycle();
        $domain = $this->cycleDomain($cycle);
        $existing = $this->application($cycle, $domain);
        $before = (array) $this->db('applications')->find($existing);

        $this->migrateOnly(self::NOMINATION_MIGRATIONS);
        $this->assertSame(self::NOMINATION_MIGRATIONS, $this->schemaDb()->table('migrations')->where('batch', 5)->orderBy('migration')->pluck('migration')->all());
        foreach (self::NOMINATION_TABLES as $table) {
            $this->assertTrue($this->schemaDb()->getSchemaBuilder()->hasTable($table), "Missing table {$table}");
        }
        $after = (array) $this->db('applications')->find($existing);
        $this->assertSame($before, array_intersect_key($after, $before), 'existing application unchanged');
        $this->assertNull($after['funding_type']);
        $this->assertSame('decimal(14,2)', $this->columnType('applications', 'funding_amount_usd'));

        // No Stage 2 data yet: the batch rolls back, the application stays.
        $this->rollbackLatestBatch();
        foreach (self::NOMINATION_TABLES as $table) {
            $this->assertFalse($this->schemaDb()->getSchemaBuilder()->hasTable($table), "{$table} should be gone");
        }
        $this->assertFalse($this->schemaDb()->getSchemaBuilder()->hasColumn('applications', 'funding_type'));
        $this->assertSame($before, (array) $this->db('applications')->find($existing));
        $this->migrateOnly(self::NOMINATION_MIGRATIONS);
    }

    public function test_rolling_back_is_refused_before_anything_changes_while_stage_2_data_exists(): void
    {
        $this->deployedSchema();
        $this->migrateOnly(self::NOMINATION_MIGRATIONS);
        $cycle = $this->cycle();
        $domain = $this->cycleDomain($cycle);
        $subcategory = $this->subcategory($cycle, $domain, 'quality');
        $profile = $this->profile();
        $application = $this->application($cycle, $domain, ['subcategory_id' => $subcategory, 'funding_type' => 'not_needed', 'applicant_id' => $profile['applicant']]);
        $this->db('application_partnerships')->insert(['application_id' => $application, 'position' => 1, 'partner_name' => 'جهة']);
        $this->journey($profile, $cycle, $application);
        $migrationsBefore = $this->schemaDb()->table('migrations')->orderBy('id')->get()->toArray();

        try {
            $this->rollbackLatestBatch();
            $this->fail('The rollback should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back the Stage 2 migrations: 1 confirmed journey(s), 1 application(s) with Stage 2 data and 1 partnership row(s) exist.', $exception->getMessage());
        }

        foreach (self::NOMINATION_TABLES as $table) {
            $this->assertTrue($this->schemaDb()->getSchemaBuilder()->hasTable($table), "{$table} must be kept");
        }
        $this->assertSame('not_needed', $this->db('applications')->where('id', $application)->value('funding_type'));
        $this->assertEquals($migrationsBefore, $this->schemaDb()->table('migrations')->orderBy('id')->get()->toArray());
    }

    public function test_subcategories_stay_inside_their_domain_and_cycle(): void
    {
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();

        $cycleA = $this->cycle();
        $cycleB = $this->cycle();
        $cultureA = $this->cycleDomain($cycleA);
        $creativityA = $this->cycleDomain($cycleA);
        $cultureB = $this->cycleDomain($cycleB);
        $arts = $this->subcategory($cycleA, $cultureA, 'arts');
        $quality = $this->subcategory($cycleA, $creativityA, 'quality');
        $artsB = $this->subcategory($cycleB, $cultureB, 'arts');

        $this->assertDatabaseRejects('duplicate', fn () => $this->subcategory($cycleA, $cultureA, 'arts'), 'same code twice in a domain');
        $this->assertDatabaseRejects('duplicate', fn () => $this->subcategory($cycleA, $creativityA, 'arts'), 'same code twice in a cycle');
        $this->assertDatabaseRejects('missing_parent', fn () => $this->subcategory($cycleB, $cultureA, 'culture'), 'subcategory pointing at another cycle\'s domain');
        $this->assertDatabaseRejects('check_failed', fn () => $this->subcategory($cycleA, $cultureA, ''), 'empty code');

        $this->application($cycleA, $cultureA, ['subcategory_id' => $arts]);
        $this->assertDatabaseRejects('missing_parent', fn () => $this->application($cycleA, $cultureA, ['subcategory_id' => $quality]), 'subcategory of another domain');
        $this->assertDatabaseRejects('missing_parent', fn () => $this->application($cycleA, $cultureA, ['subcategory_id' => $artsB]), 'subcategory of another cycle');
        $this->assertDatabaseRejects('parent_in_use', fn () => $this->db('cycle_domain_subcategories')->where('id', $arts)->delete(), 'subcategory in use');
    }

    public function test_funding_declarations_and_journeys_are_consistent(): void
    {
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();
        $cycle = $this->cycle();
        $domain = $this->cycleDomain($cycle);

        $valid = [
            ['fully_funded', 'جهة', '1500.50'], ['partially_funded', 'جهة', '0.01'], ['self_funded', null, '999999999999.99'], ['not_needed', null, null], [null, null, null],
        ];
        foreach ($valid as [$type, $funder, $amount]) {
            $id = $this->application($cycle, $domain, ['funding_type' => $type, 'funder_name' => $funder, 'funding_amount_usd' => $amount]);
            $this->assertSame($amount, $this->db('applications')->where('id', $id)->value('funding_amount_usd'), "exact amount for {$type}");
        }

        $invalid = [
            'unknown type' => ['grant', null, null],
            'funded without funder' => ['fully_funded', null, '10.00'],
            'funded without amount' => ['partially_funded', 'جهة', null],
            'self-funded with funder' => ['self_funded', 'جهة', '10.00'],
            'self-funded without amount' => ['self_funded', null, null],
            'no funding with amount' => ['not_needed', null, '10.00'],
            'no funding with funder' => ['not_needed', 'جهة', null],
            'values without a type' => [null, 'جهة', '10.00'],
            'zero amount' => ['self_funded', null, '0.00'],
            'negative amount' => ['self_funded', null, '-5.00'],
            'empty funder' => ['fully_funded', '', '10.00'],
        ];
        foreach ($invalid as $label => [$type, $funder, $amount]) {
            $this->assertDatabaseRejects('check_failed', fn () => $this->application($cycle, $domain, ['funding_type' => $type, 'funder_name' => $funder, 'funding_amount_usd' => $amount]), $label);
        }

        // Partnerships: positions 1–10, unique per application.
        $application = $this->application($cycle, $domain);
        $this->db('application_partnerships')->insert(['application_id' => $application, 'position' => 1, 'partner_name' => 'جهة']);
        $this->assertDatabaseRejects('duplicate', fn () => $this->db('application_partnerships')->insert(['application_id' => $application, 'position' => 1, 'partner_name' => 'أخرى']), 'position twice');
        $this->assertDatabaseRejects('check_failed', fn () => $this->db('application_partnerships')->insert(['application_id' => $application, 'position' => 11, 'partner_name' => 'جهة']), 'eleventh partner');

        // Journeys: one per profile and cycle, one per application, same cycle as the application.
        $profile = $this->profile();
        $mine = $this->application($cycle, $domain, ['applicant_id' => $profile['applicant']]);
        $this->journey($profile, $cycle, $mine);
        $this->assertDatabaseRejects('duplicate', fn () => $this->journey($profile, $cycle, $this->application($cycle, $domain, ['applicant_id' => $profile['applicant']])), 'second journey in a cycle');
        $this->assertDatabaseRejects('duplicate', fn () => $this->journey($this->profile(), $cycle, $mine), 'application in two journeys');
        $otherCycle = $this->cycle();
        $this->assertDatabaseRejects('missing_parent', fn () => $this->journey($this->profile(), $otherCycle, $this->application($cycle, $domain)), 'journey cycle differs from the application');
    }

    private function deployedSchema(): void
    {
        $this->wipeDisposableDatabase();
        $this->migrateBaseline();
        $this->migrateOnly($this->platformMigrations());
        $this->migrateOnly(self::ACCOUNT_MIGRATIONS);
        $this->migrateOnly(self::CANDIDATE_MIGRATIONS);
    }

    private function db(string $table): Builder
    {
        return $this->schemaDb()->table($table);
    }

    private function user(): int
    {
        $n = ++$this->sequence;

        return $this->db('users')->insertGetId(['name' => "User {$n}", 'email' => "nomination{$n}.".uniqid().'@example.org', 'password' => 'not-a-real-hash']);
    }

    private function cycle(): int
    {
        return $this->db('award_cycles')->insertGetId([
            'code' => 'N'.(++$this->sequence).substr(uniqid(), -6), 'name_ar' => 'الدورة', 'award_name_ar' => 'جائزة', 'year' => 2026, 'timezone' => 'Asia/Damascus',
        ]);
    }

    private function cycleDomain(int $cycle): int
    {
        $domain = $this->db('award_domains')->insertGetId(['code' => 'domain_'.(++$this->sequence).uniqid(), 'name_ar' => 'محور']);

        return $this->db('cycle_domains')->insertGetId(['cycle_id' => $cycle, 'domain_id' => $domain, 'name_ar' => 'محور']);
    }

    private function subcategory(int $cycle, int $cycleDomain, string $code): int
    {
        return $this->db('cycle_domain_subcategories')->insertGetId(['cycle_id' => $cycle, 'cycle_domain_id' => $cycleDomain, 'code' => $code, 'name_ar' => 'فئة']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function application(int $cycle, int $cycleDomain, array $overrides = []): int
    {
        $applicant = $overrides['applicant_id'] ?? $this->db('applicants')->insertGetId(['type' => 'individual', 'name_ar' => 'متقدم', 'representative_user_id' => $this->user()]);

        return $this->db('applications')->insertGetId($overrides + [
            'cycle_id' => $cycle, 'cycle_domain_id' => $cycleDomain, 'applicant_id' => $applicant, 'created_by' => $this->user(), 'status' => 'draft',
        ]);
    }

    /**
     * @return array{user: int, applicant: int, profile: int}
     */
    private function profile(): array
    {
        $user = $this->user();
        $applicant = $this->db('applicants')->insertGetId(['type' => 'individual', 'name_ar' => 'متقدم', 'representative_user_id' => $user]);
        $profile = $this->db('candidate_profiles')->insertGetId([
            'user_id' => $user, 'applicant_id' => $applicant, 'profile_type' => 'individual',
            'data_accuracy_declared_at' => self::NOW, 'completed_at' => self::NOW, 'created_at' => self::NOW,
        ]);

        return ['user' => $user, 'applicant' => $applicant, 'profile' => $profile];
    }

    /**
     * @param  array{user: int, applicant: int, profile: int}  $profile
     */
    private function journey(array $profile, int $cycle, int $application): int
    {
        return $this->db('candidate_nominations')->insertGetId([
            'user_id' => $profile['user'], 'candidate_profile_id' => $profile['profile'], 'applicant_id' => $profile['applicant'],
            'cycle_id' => $cycle, 'application_id' => $application, 'details_confirmed_at' => self::NOW, 'created_at' => self::NOW,
        ]);
    }

    private function columnType(string $table, string $column): string
    {
        return (string) $this->schemaDb()->selectOne(
            'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        )->type;
    }
}
