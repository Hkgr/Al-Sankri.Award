<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;
use Tests\UsesDisposableSchemaDatabase;

/**
 * The candidate migrations (300001–300003) on a disposable MySQL or MariaDB
 * database: applying them on a deployed schema, rolling back only while no
 * candidate data exists, and the constraints of the new tables.
 */
class CandidateSchemaTest extends TestCase
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

    public function test_the_candidate_migrations_apply_on_a_deployed_schema_and_roll_back_while_empty(): void
    {
        // Production: the foundation and the account migrations already applied, with an account.
        $this->deployedSchema();
        $userId = $this->user();

        $this->migrateOnly(self::CANDIDATE_MIGRATIONS);
        $this->assertSame(self::CANDIDATE_MIGRATIONS, $this->schemaDb()->table('migrations')->where('batch', 4)->orderBy('migration')->pluck('migration')->all());
        foreach (self::CANDIDATE_TABLES as $table) {
            $this->assertTrue($this->schemaDb()->getSchemaBuilder()->hasTable($table), "Missing table {$table}");
        }
        $role = $this->db('roles')->where('code', 'candidate')->sole();
        $this->assertSame(['مترشح', 'platform'], [$role->name_ar, $role->scope]);
        $this->assertSame(0, $this->db('user_roles')->count(), 'existing accounts are classified by the provisioning command, not the migration');

        $this->assertSame(1, $this->db('audit_logs')->where('action', 'role.provisioned')->where('subject_id', $role->id)->count(), 'the migration records the role it created');

        // Nothing depends on the new tables yet: the batch rolls back cleanly,
        // and the role this migration created is removed.
        $this->rollbackLatestBatch();
        foreach (self::CANDIDATE_TABLES as $table) {
            $this->assertFalse($this->schemaDb()->getSchemaBuilder()->hasTable($table), "{$table} should be gone");
        }
        $this->assertSame(0, $this->db('roles')->where('code', 'candidate')->count());
        $this->assertNotNull($this->db('users')->find($userId));

        // Idempotent: a candidate role that already exists is kept as it is.
        $existing = $this->db('roles')->insertGetId(['code' => 'candidate', 'name_ar' => 'مترشح', 'description' => 'manual', 'scope' => 'platform']);
        $this->migrateOnly(self::CANDIDATE_MIGRATIONS);
        $this->assertSame([$existing], $this->db('roles')->where('code', 'candidate')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('manual', $this->db('roles')->find($existing)->description);
    }

    public function test_rolling_back_preserves_a_candidate_role_that_existed_before(): void
    {
        // A "candidate" role created by someone else, with its own metadata and permissions.
        $this->deployedSchema();
        $roleId = $this->db('roles')->insertGetId([
            'code' => 'candidate', 'name_ar' => 'مترشح (تعريف سابق)', 'description' => 'Defined before the candidate migrations.',
            'scope' => 'platform', 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-02 11:00:00',
        ]);
        $permissionId = $this->db('permissions')->insertGetId(['code' => 'profiles.view_own', 'name_ar' => 'عرض الملف', 'module' => 'profiles']);
        $this->db('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => '2026-09-02 11:00:00']);
        $roleBefore = (array) $this->db('roles')->find($roleId);
        $permissionsBefore = $this->db('role_permissions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->migrateOnly(self::CANDIDATE_MIGRATIONS);
        $this->assertSame($roleBefore, (array) $this->db('roles')->find($roleId), 'applying keeps the existing role as it is');
        $this->assertSame(0, $this->db('audit_logs')->where('action', 'role.provisioned')->count(), 'the migration did not create the role');

        $this->rollbackLatestBatch();
        foreach (self::CANDIDATE_TABLES as $table) {
            $this->assertFalse($this->schemaDb()->getSchemaBuilder()->hasTable($table), "{$table} should be gone");
        }
        $this->assertSame($roleBefore, (array) $this->db('roles')->find($roleId), 'rollback keeps the pre-existing role');
        $this->assertSame($permissionsBefore, $this->db('role_permissions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, $this->db('permissions')->where('id', $permissionId)->count());
    }

    public function test_rolling_back_is_refused_before_anything_changes_while_candidate_data_exists(): void
    {
        $this->deployedSchema();
        $this->migrateOnly(self::CANDIDATE_MIGRATIONS);
        $userId = $this->user();
        $roleId = (int) $this->db('roles')->where('code', 'candidate')->value('id');
        $this->db('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId, 'assigned_at' => self::NOW]);
        $applicant = $this->applicant($userId, 'individual');
        $this->profile($userId, $applicant, 'individual');
        $policy = $this->policy('privacy_policy', 1, 'published');
        $this->db('account_policy_acceptances')->insert(['user_id' => $userId, 'policy_version_id' => $policy, 'context' => 'basic_profile', 'accepted_at' => self::NOW]);
        $migrationsBefore = $this->schemaDb()->table('migrations')->orderBy('id')->get()->toArray();

        try {
            $this->rollbackLatestBatch();
            $this->fail('The rollback should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back the candidate migrations: 1 confirmed profile(s), 1 policy acceptance(s) and 1 candidate role grant(s) exist.', $exception->getMessage());
        }

        foreach (self::CANDIDATE_TABLES as $table) {
            $this->assertTrue($this->schemaDb()->getSchemaBuilder()->hasTable($table), "{$table} must be kept");
        }
        $this->assertSame(1, $this->db('candidate_profiles')->count());
        $this->assertSame(1, $this->db('account_policy_acceptances')->count());
        $this->assertSame(1, $this->db('user_roles')->where('role_id', $roleId)->count());
        $this->assertEquals($migrationsBefore, $this->schemaDb()->table('migrations')->orderBy('id')->get()->toArray());
    }

    public function test_the_candidate_tables_enforce_their_constraints(): void
    {
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();

        // One basic profile per account and per applicant.
        $user = $this->user();
        $applicant = $this->applicant($user, 'individual');
        $this->profile($user, $applicant, 'individual');
        $this->assertDatabaseRejects('duplicate', fn () => $this->profile($user, $this->applicant($user, 'individual'), 'individual'), 'second profile for an account');
        $this->assertDatabaseRejects('duplicate', fn () => $this->profile($this->user(), $applicant, 'individual'), 'second profile for an applicant');
        $this->assertDatabaseRejects('check_failed', fn () => $this->profile($this->user(), $this->applicant(null, 'team'), 'team'), 'profile type');
        $this->assertDatabaseRejects('check_failed', fn () => $this->profile($this->user(), $this->applicant(null, 'organization'), 'institution', representation: false), 'institution without representation declaration');
        $this->assertDatabaseRejects('check_failed', fn () => $this->profile($this->user(), $this->applicant(null, 'individual'), 'individual', representation: true), 'individual with representation declaration');
        $this->assertDatabaseRejects('missing_parent', fn () => $this->profile(999999999, $this->applicant(null, 'individual'), 'individual'), 'unknown account');
        $this->assertDatabaseRejects('parent_in_use', fn () => $this->db('users')->where('id', $user)->delete(), 'account with a profile');

        // Individual details.
        $this->individual($applicant);
        $this->assertDatabaseRejects('duplicate', fn () => $this->individual($applicant), 'second individual details row');
        $this->assertDatabaseRejects('check_failed', fn () => $this->individual($this->applicant(null, 'individual'), ['gender' => 'unknown']), 'gender');
        foreach (['sy', 'S', 'S1', 'سو'] as $code) {
            $this->assertDatabaseRejects('check_failed', fn () => $this->individual($this->applicant(null, 'individual'), ['nationality_country_code' => $code]), "nationality {$code}");
        }
        $this->assertDatabaseRejects('too_long', fn () => $this->individual($this->applicant(null, 'individual'), ['first_name' => str_repeat('ع', 101)]), 'first name length');

        // Institution details and links accept only web addresses.
        $organization = $this->applicant(null, 'organization');
        $this->db('applicant_institution_details')->insert(['applicant_id' => $organization, 'activity_type' => 'تراث', 'website_url' => null]);
        $this->assertDatabaseRejects('check_failed', fn () => $this->db('applicant_institution_details')->insert([
            'applicant_id' => $this->applicant(null, 'organization'), 'activity_type' => 'تراث', 'website_url' => 'javascript:alert(1)',
        ]), 'website scheme');
        $this->link($organization, 1, 'https://example.org/a');
        $this->assertDatabaseRejects('duplicate', fn () => $this->link($organization, 1, 'https://example.org/b'), 'link position');
        $this->assertDatabaseRejects('check_failed', fn () => $this->link($organization, 6, 'https://example.org/c'), 'sixth link');
        $this->assertDatabaseRejects('check_failed', fn () => $this->link($organization, 2, 'data:text/html,x'), 'link scheme');

        // Policies: closed codes, one published version per policy, exact acceptances.
        $first = $this->policy('terms_of_use', 1, 'published');
        $this->assertDatabaseRejects('duplicate', fn () => $this->policy('terms_of_use', 2, 'published'), 'two published versions');
        $this->assertDatabaseRejects('duplicate', fn () => $this->policy('terms_of_use', 1, 'draft'), 'version number');
        $this->assertDatabaseRejects('check_failed', fn () => $this->policy('cookies', 1, 'draft'), 'policy code');
        $this->assertDatabaseRejects('check_failed', fn () => $this->db('account_policy_versions')->insert([
            'code' => 'privacy_policy', 'version_number' => 9, 'title_ar' => 'x', 'body_ar' => 'x', 'status' => 'published', 'published_at' => null,
        ]), 'published without a date');
        $this->assertDatabaseRejects('check_failed', fn () => $this->policy('privacy_policy', 0, 'draft'), 'version zero');
        $this->db('account_policy_acceptances')->insert(['user_id' => $user, 'policy_version_id' => $first, 'context' => 'basic_profile', 'accepted_at' => self::NOW]);
        $this->assertDatabaseRejects('duplicate', fn () => $this->db('account_policy_acceptances')->insert([
            'user_id' => $user, 'policy_version_id' => $first, 'context' => 'basic_profile', 'accepted_at' => self::NOW,
        ]), 'second acceptance of a version');
        $this->assertDatabaseRejects('check_failed', fn () => $this->db('account_policy_acceptances')->insert([
            'user_id' => $this->user(), 'policy_version_id' => $first, 'context' => 'marketing', 'accepted_at' => self::NOW,
        ]), 'acceptance context');
        $this->assertDatabaseRejects('parent_in_use', fn () => $this->db('account_policy_versions')->where('id', $first)->delete(), 'accepted policy version');
        $this->db('account_policy_versions')->where('id', $first)->update(['status' => 'retired']);
        $this->policy('terms_of_use', 2, 'published');
    }

    public function test_the_identity_number_column_holds_only_ciphertext(): void
    {
        $this->ensurePlatformSchema();
        $this->schemaDb()->beginTransaction();

        $applicant = $this->applicant($this->user(), 'individual');
        $this->individual($applicant, ['national_id_number' => Crypt::encryptString('00123/AB-9')]);

        $stored = (string) $this->db('applicant_individual_details')->where('applicant_id', $applicant)->value('national_id_number');
        $this->assertStringNotContainsString('00123', $stored);
        $this->assertSame('00123/AB-9', Crypt::decryptString($stored));
        $this->assertSame('text', $this->schemaDb()->selectOne(
            "SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_individual_details' AND COLUMN_NAME = 'national_id_number'"
        )->type);
        $this->assertSame(0, (int) $this->schemaDb()->selectOne(
            "SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant_individual_details' AND COLUMN_NAME = 'national_id_number'"
        )->total, 'no index on the identity number');
    }

    private function deployedSchema(): void
    {
        $this->wipeDisposableDatabase();
        $this->migrateBaseline();
        $this->migrateOnly($this->platformMigrations());
        $this->migrateOnly(self::ACCOUNT_MIGRATIONS);
    }

    private function db(string $table): Builder
    {
        return $this->schemaDb()->table($table);
    }

    private function user(): int
    {
        $n = ++$this->sequence;

        return $this->db('users')->insertGetId([
            'name' => "User {$n}", 'email' => "candidate{$n}.".uniqid().'@example.org', 'password' => 'not-a-real-hash', 'email_verified_at' => self::NOW,
        ]);
    }

    private function applicant(?int $userId, string $type): int
    {
        return $this->db('applicants')->insertGetId(['type' => $type, 'name_ar' => 'متقدم', 'representative_user_id' => $userId ?? $this->user()]);
    }

    private function profile(int $userId, int $applicantId, string $type, ?bool $representation = null): int
    {
        $representation ??= $type === 'institution';

        return $this->db('candidate_profiles')->insertGetId([
            'user_id' => $userId, 'applicant_id' => $applicantId, 'profile_type' => $type,
            'data_accuracy_declared_at' => self::NOW, 'representation_declared_at' => $representation ? self::NOW : null,
            'completed_at' => self::NOW, 'created_at' => self::NOW,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function individual(int $applicantId, array $overrides = []): int
    {
        return $this->db('applicant_individual_details')->insertGetId($overrides + [
            'applicant_id' => $applicantId, 'first_name' => 'ليلى', 'last_name' => 'حسن', 'birth_date' => '1990-01-01',
            'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => 'ciphertext',
        ]);
    }

    private function link(int $applicantId, int $position, string $url): int
    {
        return $this->db('applicant_social_links')->insertGetId(['applicant_id' => $applicantId, 'position' => $position, 'platform' => 'Web', 'url' => $url]);
    }

    private function policy(string $code, int $number, string $status): int
    {
        return $this->db('account_policy_versions')->insertGetId([
            'code' => $code, 'version_number' => $number, 'title_ar' => 'نص تجريبي', 'body_ar' => 'نص تجريبي غير معتمد',
            'status' => $status, 'published_at' => $status === 'draft' ? null : self::NOW,
        ]);
    }
}
