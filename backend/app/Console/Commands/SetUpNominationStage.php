<?php

namespace App\Console\Commands;

use App\Candidates\NominationCycles;
use App\Candidates\NominationTaxonomy;
use App\Models\User;
use App\Support\AuditLog;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Configures Stage 2 («فئة الترشح وبيانات العمل») for one explicitly named
 * award cycle. There is no admin portal; this is the operator workflow.
 *
 * Every step is idempotent and only adds or changes what was asked:
 *
 * - the cycle: an existing one, or a new draft with --create-cycle and its
 *   explicit name, award name, year and timezone (nothing is invented);
 * - the three approved domains and seven subcategories
 *   (App\Candidates\NominationTaxonomy): created when missing, existing
 *   records and identifiers kept as they are;
 * - the cycle's implementation country (--country), never overwritten;
 * - the stage `nomination_details`: created as planned, with an optional
 *   window; --enable / --close change its status;
 * - --open-domains opens the approved domains for applications;
 * - --publish-cycle publishes a draft cycle.
 *
 * Changes are attributed to --actor (an existing account) in audit_logs.
 * --dry-run performs everything inside a transaction and rolls it back.
 * Nothing here opens final submission, creates versions or slots.
 */
#[Signature('nomination:setup
    {cycle : Code of the award cycle to configure}
    {--actor= : Email of the operator account the changes are attributed to}
    {--create-cycle : Create the cycle as a draft when it does not exist}
    {--name= : Arabic name of the cycle (with --create-cycle)}
    {--award-name= : Arabic award name used in this cycle (with --create-cycle)}
    {--year= : Year of the cycle (with --create-cycle)}
    {--timezone= : IANA timezone of the cycle, e.g. Asia/Damascus (with --create-cycle)}
    {--country= : Implementation country of the cycle (ISO code; only SY is supported)}
    {--opens-at= : Stage opening, "Y-m-d H:i" in the cycle timezone}
    {--closes-at= : Stage closing, "Y-m-d H:i" in the cycle timezone}
    {--open-domains : Open the approved domains of this cycle for applications}
    {--enable : Enable the nomination_details stage}
    {--close : Close the nomination_details stage}
    {--publish-cycle : Publish the cycle if it is still a draft}
    {--dry-run : Show what would change, and change nothing}')]
#[Description('Configure Stage 2 (nomination category and work details) for an explicitly named award cycle')]
class SetUpNominationStage extends Command
{
    private const STAGE_NAME = 'فئة الترشح وبيانات العمل';

    /**
     * @var list<string>
     */
    private array $changes = [];

    /** The cycle being configured, for the audit rows' cycle attribution. */
    private ?int $cycleId = null;

    /**
     * Execute the console command.
     */
    public function handle(NominationCycles $cycles): int
    {
        // The command object can be reused in one process (tests, queues).
        $this->changes = [];
        $this->cycleId = null;

        if ($this->option('enable') && $this->option('close')) {
            $this->error('Use either --enable or --close, not both.');

            return self::INVALID;
        }

        $actor = User::query()->where('email', (string) $this->option('actor'))->first();
        if ($actor === null) {
            $this->error('Give --actor= with the email of an existing operator account; changes are attributed to it.');

            return self::INVALID;
        }

        DB::beginTransaction();

        try {
            // Lock order shared with the Stage 2 confirmation: the account's
            // users row first, then the configuration (award_cycles, stage,
            // options). Every audit row written below references the actor,
            // and that foreign-key check takes a shared lock on the actor's
            // users row; taking it here, before any cycle lock, means a
            // confirmation by the same account (which holds its users row,
            // then locks award_cycles) is waited for, or waits, instead of
            // deadlocking against this command.
            DB::table('users')->where('id', $actor->getKey())->sharedLock()->first();

            $cycle = $this->cycle($actor);
            $this->cycleId = (int) $cycle->id;
            $this->domains($cycle, $actor);
            $this->country($cycle, $actor);
            $this->stage($cycle, $actor);
            if ($this->option('publish-cycle')) {
                $this->publish($cycle, $actor);
            }
            $availability = $cycles->current();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($this->changes ?: ['Nothing to change.'] as $change) {
            $this->line('- '.$change);
        }
        $this->line('Stage 2 availability after these changes: '.$availability['status']
            .($availability['cycle'] ? " (cycle {$availability['cycle']->code})" : ''));

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function cycle(User $actor): object
    {
        $code = (string) $this->argument('cycle');
        $cycle = DB::table('award_cycles')->where('code', $code)->lockForUpdate()->first();

        if ($cycle !== null) {
            return $cycle;
        }

        if (! $this->option('create-cycle')) {
            throw new RuntimeException("No award cycle with code [{$code}]. Pass --create-cycle with --name, --award-name, --year and --timezone to create it as a draft.");
        }

        $name = trim((string) $this->option('name'));
        $awardName = trim((string) $this->option('award-name'));
        $year = (string) $this->option('year');
        $timezone = (string) $this->option('timezone');

        if ($name === '' || $awardName === '' || ! ctype_digit($year) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Creating a cycle needs --name, --award-name, a numeric --year and a valid IANA --timezone.');
        }

        $id = DB::table('award_cycles')->insertGetId([
            'code' => $code, 'name_ar' => $name, 'award_name_ar' => $awardName, 'year' => (int) $year,
            'timezone' => $timezone, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->record($actor, 'award_cycle.created', 'award_cycle', $id, ['code' => $code], "Created draft cycle {$code}.");

        return DB::table('award_cycles')->where('id', $id)->first();
    }

    private function domains(object $cycle, User $actor): void
    {
        $order = 0;

        foreach (NominationTaxonomy::DOMAINS as $code => $definition) {
            $order++;
            $domainId = DB::table('award_domains')->where('code', $code)->value('id');

            if ($domainId === null) {
                $domainId = DB::table('award_domains')->insertGetId([
                    'code' => $code, 'name_ar' => $definition['name'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->record($actor, 'award_domain.created', 'award_domain', $domainId, ['code' => $code], "Created domain {$code}.");
            }

            $cycleDomain = DB::table('cycle_domains')->where('cycle_id', $cycle->id)->where('domain_id', $domainId)->first();

            if ($cycleDomain === null) {
                $cycleDomainId = DB::table('cycle_domains')->insertGetId([
                    'cycle_id' => $cycle->id, 'domain_id' => $domainId, 'name_ar' => $definition['name'], 'display_order' => $order,
                    'is_open_for_applications' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->record($actor, 'cycle_domain.created', 'cycle_domain', $cycleDomainId, ['cycle_id' => $cycle->id, 'domain' => $code], "Added domain {$code} to the cycle.");
                $cycleDomain = DB::table('cycle_domains')->where('id', $cycleDomainId)->first();
            }

            $subOrder = 0;
            foreach ($definition['subcategories'] as $subcategoryCode => $subcategoryName) {
                $subOrder++;
                $exists = DB::table('cycle_domain_subcategories')->where('cycle_domain_id', $cycleDomain->id)->where('code', $subcategoryCode)->exists();
                if (! $exists) {
                    $id = DB::table('cycle_domain_subcategories')->insertGetId([
                        'cycle_id' => $cycle->id, 'cycle_domain_id' => $cycleDomain->id, 'code' => $subcategoryCode, 'name_ar' => $subcategoryName,
                        'display_order' => $subOrder, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->record($actor, 'cycle_domain_subcategory.created', 'cycle_domain_subcategory', $id, ['code' => $subcategoryCode], "Added subcategory {$subcategoryCode} to {$code}.");
                }
            }

            if ($this->option('open-domains') && ! $cycleDomain->is_open_for_applications) {
                DB::table('cycle_domains')->where('id', $cycleDomain->id)->update(['is_open_for_applications' => true, 'updated_at' => now()]);
                $this->record($actor, 'cycle_domain.opened', 'cycle_domain', (int) $cycleDomain->id, ['domain' => $code], "Opened domain {$code} for applications.");
            }
        }
    }

    private function country(object $cycle, User $actor): void
    {
        $country = $this->option('country');

        if ($country === null) {
            return;
        }

        if (! in_array($country, NominationCycles::SUPPORTED_COUNTRIES, true)) {
            throw new RuntimeException("Unsupported --country [{$country}]: only ".implode(', ', NominationCycles::SUPPORTED_COUNTRIES).' has a vetted governorate list.');
        }

        $rules = json_decode((string) $cycle->eligibility_rules, true) ?: [];
        $existing = $rules['implementation_country'] ?? null;

        if ($existing === $country) {
            return;
        }

        if ($existing !== null) {
            throw new RuntimeException("The cycle's implementation country is already [{$existing}]; it is never overwritten.");
        }

        $rules['implementation_country'] = $country;
        DB::table('award_cycles')->where('id', $cycle->id)->update(['eligibility_rules' => json_encode($rules), 'updated_at' => now()]);
        $this->record($actor, 'award_cycle.geography_set', 'award_cycle', (int) $cycle->id, ['implementation_country' => $country], "Set the implementation country to {$country}.");
    }

    private function stage(object $cycle, User $actor): void
    {
        $stage = DB::table('cycle_stages')->where('cycle_id', $cycle->id)->where('code', NominationCycles::STAGE_CODE)->lockForUpdate()->first();
        $window = array_filter([
            'opens_at' => $this->time('opens-at', $cycle->timezone),
            'closes_at' => $this->time('closes-at', $cycle->timezone),
        ]);

        if ($stage === null) {
            $id = DB::table('cycle_stages')->insertGetId($window + [
                'cycle_id' => $cycle->id, 'code' => NominationCycles::STAGE_CODE, 'name_ar' => self::STAGE_NAME,
                'status' => 'planned', 'display_order' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($actor, 'cycle_stage.created', 'cycle_stage', $id, ['code' => NominationCycles::STAGE_CODE], 'Created the nomination_details stage (planned).');
            $stage = DB::table('cycle_stages')->where('id', $id)->first();
        } elseif ($window !== []) {
            DB::table('cycle_stages')->where('id', $stage->id)->update($window + ['last_change_reason' => 'nomination:setup window', 'updated_at' => now()]);
            $this->record($actor, 'cycle_stage.window_changed', 'cycle_stage', (int) $stage->id, [], 'Changed the stage window.');
        }

        if ($this->option('enable') && $stage->status !== 'enabled') {
            DB::table('cycle_stages')->where('id', $stage->id)->update([
                'status' => 'enabled', 'enabled_by' => $actor->getKey(), 'enabled_at' => now(), 'last_change_reason' => 'nomination:setup --enable', 'updated_at' => now(),
            ]);
            $this->record($actor, 'cycle_stage.enabled', 'cycle_stage', (int) $stage->id, [], 'Enabled the nomination_details stage.');
        }

        if ($this->option('close') && $stage->status !== 'closed') {
            DB::table('cycle_stages')->where('id', $stage->id)->update(['status' => 'closed', 'last_change_reason' => 'nomination:setup --close', 'updated_at' => now()]);
            $this->record($actor, 'cycle_stage.closed', 'cycle_stage', (int) $stage->id, [], 'Closed the nomination_details stage.');
        }
    }

    private function publish(object $cycle, User $actor): void
    {
        if ($cycle->status !== 'draft') {
            return;
        }

        DB::table('award_cycles')->where('id', $cycle->id)->update(['status' => 'published', 'published_at' => now(), 'updated_at' => now()]);
        $this->record($actor, 'award_cycle.published', 'award_cycle', (int) $cycle->id, [], 'Published the cycle.');
    }

    private function time(string $option, string $timezone): ?Carbon
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        $time = Carbon::createFromFormat('Y-m-d H:i', (string) $value, $timezone);
        if ($time === false || $time->format('Y-m-d H:i') !== $value) {
            throw new RuntimeException("--{$option} must be \"Y-m-d H:i\" in the cycle timezone ({$timezone}).");
        }

        return $time->utc();
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function record(User $actor, string $action, string $subjectType, int $subjectId, array $after, string $summary): void
    {
        // The configured cycle (a new cycle is its own subject); global domains stay unattributed.
        $cycleId = match (true) {
            $subjectType === 'award_domain' => null,
            $subjectType === 'award_cycle' => $subjectId,
            default => $this->cycleId,
        };
        AuditLog::record($action, (int) $actor->getKey(), $subjectType, $subjectId, $after ?: null, $cycleId);
        $this->changes[] = $summary;
    }
}
