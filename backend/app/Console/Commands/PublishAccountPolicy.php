<?php

namespace App\Console\Commands;

use App\Models\AccountPolicyVersion;
use App\Support\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the owner-approved text of an account policy as its next
 * version (the minimal activation procedure; there is no admin portal).
 *
 * The text is read from a UTF-8 file. Publishing retires the previously
 * published version in the same transaction, so exactly one version is
 * current. Published text is never edited: to change it, publish again.
 * New acceptances then require the new version; earlier acceptances keep
 * pointing at the exact version that was accepted.
 */
#[Signature('account-policies:publish
    {policy : privacy_policy or terms_of_use}
    {file : Path to a UTF-8 text file with the approved Arabic text}
    {--title= : The Arabic title shown above the text}
    {--dry-run : Show what would be published without writing anything}
    {--force : Publish without asking for confirmation}')]
#[Description('Publish an approved version of the privacy policy or the terms of use')]
class PublishAccountPolicy extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $code = (string) $this->argument('policy');
        $path = (string) $this->argument('file');
        $title = trim((string) $this->option('title'));

        if (! array_key_exists($code, AccountPolicyVersion::NAMES)) {
            $this->error('Unknown policy: use '.implode(' or ', array_keys(AccountPolicyVersion::NAMES)).'.');

            return self::INVALID;
        }

        $body = is_file($path) ? file_get_contents($path) : false;

        if ($body === false || trim($body) === '' || ! mb_check_encoding($body, 'UTF-8')) {
            $this->error("Cannot read non-empty UTF-8 text from [{$path}].");

            return self::INVALID;
        }

        if ($title === '' || mb_strlen($title) > 255) {
            $this->error('Give the Arabic title with --title= (at most 255 characters).');

            return self::INVALID;
        }

        $body = str_replace(["\r\n", "\r"], "\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $body)));
        $next = (int) AccountPolicyVersion::query()->where('code', $code)->max('version_number') + 1;

        $this->line("Policy:  {$code}");
        $this->line("Version: {$next}");
        $this->line("Title:   {$title}");
        $this->line('Text:    '.mb_strlen($body).' characters, sha256 '.hash('sha256', $body));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was published.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Publish this approved text? New acceptances will require this version.')) {
            $this->warn('Nothing was published.');

            return self::FAILURE;
        }

        $version = DB::transaction(function () use ($code, $title, $body): AccountPolicyVersion {
            // Lock every version of this policy, so a concurrent publication
            // or acceptance sees either the old state or the new one.
            $existing = AccountPolicyVersion::query()->where('code', $code)->lockForUpdate()->get();

            AccountPolicyVersion::query()
                ->where('code', $code)
                ->where('status', AccountPolicyVersion::STATUS_PUBLISHED)
                ->update(['status' => AccountPolicyVersion::STATUS_RETIRED, 'updated_at' => now()]);

            $version = new AccountPolicyVersion;
            $version->forceFill([
                'code' => $code,
                'version_number' => (int) $existing->max('version_number') + 1,
                'title_ar' => $title,
                'body_ar' => $body,
                'status' => AccountPolicyVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            AuditLog::record('account_policy.published', null, 'account_policy_version', (int) $version->getKey(), [
                'code' => $code,
                'version_number' => $version->version_number,
                'body_sha256' => hash('sha256', $body),
            ]);

            return $version;
        });

        $this->info("Published {$code} version {$version->version_number}.");

        return self::SUCCESS;
    }
}
