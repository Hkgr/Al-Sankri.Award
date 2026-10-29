<?php

namespace Tests\Feature;

use App\Candidates\NominationCycles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Candidate\InteractsWithNominationStage;
use Tests\TestCase;

/**
 * The public schedule behind the homepage countdown: read from the cycle's
 * `nomination_details` stage (the submission cutoff) and its display-only
 * `judging` period, configured with nomination:setup.
 */
class PublicScheduleTest extends TestCase
{
    use InteractsWithNominationStage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['email' => 'operator@example.org']);
    }

    public function test_without_a_configured_cycle_the_schedule_is_empty(): void
    {
        $this->getJson('/api/public/schedule')->assertOk()
            ->assertJsonPath('data.cycle', null)->assertJsonPath('data.application', null)->assertJsonPath('data.judging', null)
            ->assertJsonStructure(['data' => ['server_time']]);
    }

    public function test_the_announced_deadline_is_the_submission_cutoff_and_the_judging_period_changes_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T09:00:37Z'));
        $this->configureCycle();
        $this->runSetup('cycle-2026', [
            '--closes-at' => '2026-11-16 00:00', '--judging-opens-at' => '2026-11-16 00:00', '--judging-closes-at' => '2026-11-26 00:00',
        ]);
        $cycleId = (int) DB::table('award_cycles')->where('code', 'cycle-2026')->value('id');

        // Damascus midnight starting 16 November is 21:00 UTC on the 15th.
        $response = $this->getJson('/api/public/schedule')->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJsonPath('data.server_time', '2026-11-01T09:00:37Z')
            ->assertJsonPath('data.cycle.timezone', 'Asia/Damascus')
            ->assertJsonPath('data.application', ['opens_at' => null, 'closes_at' => '2026-11-15T21:00:00Z', 'open' => true])
            ->assertJsonPath('data.judging', ['opens_at' => '2026-11-15T21:00:00Z', 'closes_at' => '2026-11-25T21:00:00Z']);

        // The last second of 15 November: still open, for the schedule and for submissions alike.
        $this->travelTo(Carbon::parse('2026-11-15T20:59:59Z'));
        $this->getJson('/api/public/schedule')->assertJsonPath('data.application.open', true);
        $this->assertTrue(app(NominationCycles::class)->stageOpenFor($cycleId));

        // The closing instant: closed everywhere at once.
        $this->travelTo(Carbon::parse('2026-11-15T21:00:00Z'));
        $this->getJson('/api/public/schedule')->assertJsonPath('data.application.open', false)->assertJsonPath('data.application.closes_at', '2026-11-15T21:00:00Z');
        $this->assertFalse(app(NominationCycles::class)->stageOpenFor($cycleId));

        // The judging period is display only: planned, and nothing else is created or granted.
        $this->assertSame('planned', DB::table('cycle_stages')->where('cycle_id', $cycleId)->where('code', 'judging')->value('status'));
        $this->travelTo(Carbon::parse('2026-11-20T09:00:00Z'));
        $this->getJson('/api/public/schedule')->assertJsonPath('data.judging.closes_at', '2026-11-25T21:00:00Z');
        foreach (['committees', 'judging_assignments', 'cycle_user_roles'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        // Closing the stage early is announced as closed, whatever the window says.
        $this->travelTo(Carbon::parse('2026-11-10T09:00:00Z'));
        $this->getJson('/api/public/schedule')->assertJsonPath('data.application.open', true);
        $this->runSetup('cycle-2026', ['--close' => true]);
        $this->getJson('/api/public/schedule')->assertJsonPath('data.application.open', false);
    }

    public function test_the_judging_period_is_validated_like_the_stage_window(): void
    {
        $this->configureCycle();
        $this->artisan('nomination:setup', ['cycle' => 'cycle-2026', '--actor' => $this->operator->email, '--judging-closes-at' => '2026-11-26'])
            ->assertFailed();
        $this->assertSame(0, DB::table('cycle_stages')->where('code', 'judging')->count());
    }

    public function test_incomplete_or_ambiguous_configuration_never_advertises_open_applications(): void
    {
        $this->configureCycle();
        DB::table('award_cycles')->update(['eligibility_rules' => null]);
        $this->getJson('/api/public/schedule')->assertOk()->assertJsonPath('data.application.open', false);

        $this->runSetup('cycle-2026', ['--country' => 'SY']);
        DB::table('cycle_domains')->update(['is_open_for_applications' => false]);
        $this->getJson('/api/public/schedule')->assertJsonPath('data.application.open', false);

        $this->runSetup('cycle-2026', ['--open-domains' => true]);
        $this->getJson('/api/public/schedule')->assertJsonPath('data.application.open', true);
        $this->configureCycle('another-cycle');
        $this->getJson('/api/public/schedule')->assertJsonPath('data.cycle', null)->assertJsonPath('data.application', null);
    }

    public function test_judging_setup_dry_run_rolls_back_and_preserves_permissions(): void
    {
        $this->configureCycle();
        $stages = DB::table('cycle_stages')->get()->toArray();
        $audits = DB::table('audit_logs')->count();
        $this->runSetup('cycle-2026', [
            '--judging-opens-at' => '2026-11-16 00:00', '--judging-closes-at' => '2026-11-26 00:00', '--dry-run' => true,
        ]);
        $this->assertEquals($stages, DB::table('cycle_stages')->get()->toArray());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }
}
