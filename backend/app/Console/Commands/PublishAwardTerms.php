<?php

namespace App\Console\Commands;

use App\Candidates\AwardTerms;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Signature('award-terms:publish {cycle : Exact existing cycle code} {--actor= : Email of an existing active operator} {--dry-run : Preview the selected cycle without writing}')]
#[Description('Publish the approved bilingual general_application_terms for one open cycle, preserving all previous acceptances')]
class PublishAwardTerms extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AwardTerms $terms): int
    {
        try {
            $result = DB::transaction(function () use ($terms): array {
                $actor = User::query()->where('email', (string) $this->option('actor'))->sharedLock()->first();
                if ($actor === null || ! $actor->isActive()) {
                    throw new InvalidArgumentException('Give --actor= with the email of an existing active operator.');
                }
                $cycle = DB::table('award_cycles')->where('code', (string) $this->argument('cycle'))->lockForUpdate()->first();
                if ($cycle === null || ! in_array($cycle->status, ['draft', 'published'], true)) {
                    throw new InvalidArgumentException('Choose an existing draft or published cycle code; historical cycles are not updated.');
                }
                $current = $terms->current((int) $cycle->id);
                $hash = hash('sha256', $terms->body('ar')."\n".$terms->body('en'));
                $changed = $current === null || $current->content_hash !== $hash;
                if ($this->option('dry-run')) {
                    $number = $changed ? (int) DB::table('policy_versions')->where('cycle_id', $cycle->id)->where('code', AwardTerms::CODE)->max('version_number') + 1 : (int) $current->version_number;

                    return ['cycle' => $cycle, 'id' => $changed ? null : $current->id, 'version' => $number, 'hash' => $hash, 'changed' => $changed, 'labels' => 0];
                }
                $id = $terms->publishForCycle((int) $cycle->id);
                $labels = 0;
                foreach ($terms->content()['ar']['domains'] as $domain) {
                    $domainIds = DB::table('cycle_domains')->join('award_domains', 'award_domains.id', '=', 'cycle_domains.domain_id')
                        ->where('cycle_domains.cycle_id', $cycle->id)->where('award_domains.code', $domain['code'])->pluck('cycle_domains.id');
                    foreach ($domain['subcategories'] as $category) {
                        $labels += DB::table('cycle_domain_subcategories')->whereIn('cycle_domain_id', $domainIds)->where('code', $category['code'])->where('name_ar', '<>', $category['name'])
                            ->update(['name_ar' => $category['name'], 'updated_at' => now()]);
                    }
                }
                if ($changed) {
                    AuditLog::record('application_terms.published', (int) $actor->id, 'policy_version', $id, ['code' => AwardTerms::CODE, 'content_hash' => $hash], (int) $cycle->id);
                }
                if ($labels > 0) {
                    AuditLog::record('application_terms.labels_updated', (int) $actor->id, 'award_cycle', (int) $cycle->id, ['count' => $labels], (int) $cycle->id);
                }
                $published = DB::table('policy_versions')->where('id', $id)->first();

                return ['cycle' => $cycle, 'id' => $id, 'version' => (int) $published->version_number, 'hash' => $published->content_hash, 'changed' => $changed, 'labels' => $labels];
            }, 3);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->line("Cycle: {$result['cycle']->code} (ID {$result['cycle']->id})");
        $this->line('Policy: '.AwardTerms::CODE.'; revision '.$terms->content()['revision']);
        $this->line("Version: {$result['version']}; policy ID: ".($result['id'] ?? 'new'));
        $this->line("SHA256: {$result['hash']}");
        $this->line("Category labels updated: {$result['labels']}");
        $this->info($this->option('dry-run') ? 'Preview only: no data changed.' : ($result['changed'] ? 'Published; previous versions and acceptances preserved.' : 'Already current; no duplicate version or publication audit event.'));

        return self::SUCCESS;
    }
}
