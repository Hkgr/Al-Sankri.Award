<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * The Stage 2 migrations (400001–400003) on a disposable MySQL or MariaDB
 * database: applying them on a deployed schema without touching existing
 * applications, refusing a rollback that would lose Stage 2 data, and the
 * constraints that keep subcategories inside their domain and cycle and
 * retired work columns empty.
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

    /**
     * Each kind of protected Stage 2 state refuses the whole batch in its
     * first migration (400003), before any table, column, constraint, row or
     * migration-history entry changes.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedStage2States(): array
    {
        return [
            'configured subcategories, no journey' => ['subcategories', '7 configured subcategory row(s)'],
            'an application with only objectives set' => ['objectives_only', '1 application(s) holding Stage 2 values'],
            'an application with only has_partnerships set' => ['has_partnerships_only', '1 application(s) holding Stage 2 values'],
            'confirmed journeys and partnerships' => ['journey', '1 confirmed journey(s), 1 partnership row(s), 1 application(s) holding Stage 2 values, 1 configured subcategory row(s)'],
        ];
    }

    #[DataProvider('protectedStage2States')]
    public function test_rolling_back_is_refused_before_anything_changes_while_stage_2_state_exists(string $state, string $described): void
    {
        $this->deployedSchema();
        $this->migrateOnly(self::NOMINATION_MIGRATIONS);
        $cycle = $this->cycle();
        $domain = $this->cycleDomain($cycle);

        match ($state) {
            'subcategories' => array_map(fn (string $code): int => $this->subcategory($cycle, $domain, $code),
                ['culture', 'arts', 'tangible_heritage', 'intangible_heritage', 'social_responsibility', 'entrepreneurship', 'quality']),
            'objectives_only' => $this->application($cycle, $domain, ['objectives' => 'هدف']),
            'has_partnerships_only' => $this->application($cycle, $domain, ['has_partnerships' => false]),
            'journey' => (function () use ($cycle, $domain): void {
                $profile = $this->profile();
                $application = $this->application($cycle, $domain, [
                    'subcategory_id' => $this->subcategory($cycle, $domain, 'quality'), 'funding_type' => 'not_needed', 'applicant_id' => $profile['applicant'],
                ]);
                $this->db('application_partnerships')->insert(['application_id' => $application, 'position' => 1, 'partner_name' => 'جهة']);
                $this->journey($profile, $cycle, $application);
            })(),
        };
        $before = $this->schemaFingerprint();

        try {
            $this->rollbackLatestBatch();
            $this->fail('The rollback should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("Cannot roll back the Stage 2 migrations: {$described} exist. Nothing was changed.", $exception->getMessage());
        }

        $this->assertEquals($before, $this->schemaFingerprint(), 'tables, columns, constraints, data and migration history unchanged');
    }

    /**
     * A later rollback step run on its own (400003 already rolled back while
     * empty) also refuses before changing anything when 400001 would refuse.
     */
    public function test_rolling_back_the_application_columns_is_refused_while_subcategories_are_configured(): void
    {
        $this->deployedSchema();
        $this->migrateOnly(self::NOMINATION_MIGRATIONS);
        $this->runArtisan('migrate:rollback', ['--step' => 1]);
        $this->assertFalse($this->schemaDb()->getSchemaBuilder()->hasTable('candidate_nominations'));
        $cycle = $this->cycle();
        $this->subcategory($cycle, $this->cycleDomain($cycle), 'quality');
        $before = $this->schemaFingerprint();

        try {
            $this->rollbackLatestBatch();
            $this->fail('The rollback should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back the Stage 2 application columns: 1 configured subcategory row(s) exist. Nothing was changed.', $exception->getMessage());
        }

        $this->assertEquals($before, $this->schemaFingerprint());
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

    public function test_retired_work_columns_refuse_values_and_journeys_are_consistent(): void
    {
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();
        $cycle = $this->cycle();
        $domain = $this->cycleDomain($cycle);

        // The funding, partnership and implementation-schedule columns are
        // retired (2026_10_29_900003): only NULL is accepted, whatever the
        // earlier funding checks allowed.
        $this->application($cycle, $domain, ['funding_type' => null, 'funder_name' => null, 'funding_amount_usd' => null]);
        $retired = [
            'funding type' => ['funding_type' => 'not_needed'],
            'funded declaration' => ['funding_type' => 'fully_funded', 'funder_name' => 'جهة', 'funding_amount_usd' => '1500.50'],
            'self-funded declaration' => ['funding_type' => 'self_funded', 'funding_amount_usd' => '10.00'],
            'partnership answer' => ['has_partnerships' => false],
            'start date' => ['implementation_started_on' => '2021-09-15'],
            'end date' => ['implementation_ended_on' => '2022-01-01'],
            'implementation state' => ['implementation_status' => 'ongoing'],
        ];
        foreach ($retired as $label => $values) {
            $this->assertDatabaseRejects('check_failed', fn () => $this->application($cycle, $domain, $values), $label);
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

    public function test_the_review_classification_check_accepts_only_complete_states(): void
    {
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();
        $cycle = $this->cycle();
        $domain = $this->cycleDomain($cycle);
        $admin = $this->user();
        $committee = $this->db('committees')->insertGetId(['cycle_id' => $cycle, 'cycle_domain_id' => $domain, 'type' => 'judging', 'name_ar' => 'لجنة', 'status' => 'approved', 'approved_by' => $admin, 'approved_at' => now()]);

        $draft = $this->application($cycle, $domain);
        $this->assertNull($this->db('applications')->where('id', $draft)->value('review_status'), 'a draft stays unclassified');
        $submitted = $this->application($cycle, $domain);
        $version = $this->db('application_versions')->insertGetId([
            'application_id' => $submitted, 'version_number' => 1, 'submission_type' => 'initial', 'submitted_by' => $admin,
            'submitted_at' => now(), 'data_snapshot' => '{}', 'form_snapshot' => '{}', 'content_hash' => hash('sha256', 'snapshot'),
        ]);
        $this->db('applications')->where('id', $submitted)->update(['status' => 'submitted', 'first_submitted_at' => now(), 'current_version_id' => $version]);
        $set = fn (array $review) => $this->db('applications')->where('id', $submitted)->update($review + [
            'review_status' => null, 'review_source' => null, 'review_decided_by' => null, 'review_decided_at' => null, 'review_reason' => null, 'review_committee_id' => null,
        ]);

        // Every complete state is accepted.
        $valid = [
            'pending' => ['review_status' => 'pending'],
            'admin referral' => ['review_status' => 'referred', 'review_source' => 'admin', 'review_decided_by' => $admin, 'review_decided_at' => now(), 'review_committee_id' => $committee],
            'legacy referral' => ['review_status' => 'referred', 'review_source' => 'legacy', 'review_decided_at' => now(), 'review_committee_id' => $committee],
            'exclusion' => ['review_status' => 'excluded', 'review_source' => 'admin', 'review_decided_by' => $admin, 'review_decided_at' => now(), 'review_reason' => 'سبب'],
        ];
        foreach ($valid as $label => $review) {
            $this->assertSame(1, $set($review), $label);
        }

        // Missing sources and incomplete decisions are refused, never let through as UNKNOWN.
        $invalid = [
            'referral without a source' => ['review_status' => 'referred', 'review_decided_at' => now(), 'review_committee_id' => $committee],
            'referral without a source but with a decider' => ['review_status' => 'referred', 'review_decided_by' => $admin, 'review_decided_at' => now(), 'review_committee_id' => $committee],
            'admin referral without a decider' => ['review_status' => 'referred', 'review_source' => 'admin', 'review_decided_at' => now(), 'review_committee_id' => $committee],
            'referral without a time' => ['review_status' => 'referred', 'review_source' => 'legacy', 'review_committee_id' => $committee],
            'referral without a committee' => ['review_status' => 'referred', 'review_source' => 'admin', 'review_decided_by' => $admin, 'review_decided_at' => now()],
            'referral with an unknown source' => ['review_status' => 'referred', 'review_source' => 'other', 'review_decided_at' => now(), 'review_committee_id' => $committee],
            'exclusion without a source' => ['review_status' => 'excluded', 'review_decided_by' => $admin, 'review_decided_at' => now(), 'review_reason' => 'سبب'],
            'exclusion without a reason' => ['review_status' => 'excluded', 'review_source' => 'admin', 'review_decided_by' => $admin, 'review_decided_at' => now()],
            'exclusion with an empty reason' => ['review_status' => 'excluded', 'review_source' => 'admin', 'review_decided_by' => $admin, 'review_decided_at' => now(), 'review_reason' => ''],
            'pending with a decider' => ['review_status' => 'pending', 'review_decided_by' => $admin],
            'source without a status' => ['review_source' => 'admin'],
        ];
        foreach ($invalid as $label => $review) {
            $this->assertDatabaseRejects('check_failed', fn () => $set($review), $label);
        }
        $this->assertSame('excluded', $this->db('applications')->where('id', $submitted)->value('review_status'), 'refused updates changed nothing');
        $this->assertDatabaseRejects('check_failed', fn () => $this->db('applications')->where('id', $draft)->update(['review_status' => 'pending']), 'a draft cannot be classified');
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

    /**
     * Everything a refused rollback must leave as it was: the tables, every
     * column and constraint of the Stage 2 tables and applications, their
     * rows, and the migration history.
     *
     * @return array<string, mixed>
     */
    private function schemaFingerprint(): array
    {
        $tables = ['applications', ...self::NOMINATION_TABLES];
        $present = array_values(array_filter($tables, fn (string $table): bool => $this->schemaDb()->getSchemaBuilder()->hasTable($table)));
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = fn (string $sql): array => array_map(fn (object $row): array => (array) $row, $this->schemaDb()->select($sql, $tables));

        return [
            'tables' => $present,
            'columns' => $rows("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME, ORDINAL_POSITION"),
            'constraints' => $rows("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE"),
            'indexes' => $rows("SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"),
            'data' => array_combine($present, array_map(fn (string $table): array => $this->db($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(), $present)),
            'migrations' => $this->schemaDb()->table('migrations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function columnType(string $table, string $column): string
    {
        return (string) $this->schemaDb()->selectOne(
            'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        )->type;
    }
}
