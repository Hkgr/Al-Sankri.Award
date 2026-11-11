<?php

namespace App\Candidates;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AwardTerms
{
    public const CODE = 'general_application_terms';

    /** @return array<string, mixed> */
    public function content(): array
    {
        return json_decode(file_get_contents(resource_path('award/terms-2026-11-10.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function body(string $locale): string
    {
        $content = $this->content()[$locale];
        $lines = [$content['title']];
        foreach ($content['general'] as $condition) {
            $lines[] = $condition['title'].': '.$condition['text'];
        }
        foreach ($content['domains'] as $domain) {
            $lines[] = $domain['name'];
            $lines[] = $domain['intro'];
            foreach ($domain['subcategories'] as $category) {
                $lines[] = $category['name'];
                foreach ($category['requirements'] as $condition) {
                    $lines[] = '- '.$condition;
                }
            }
        }

        return implode("\n\n", $lines);
    }

    /** Append a new immutable version; existing accepted texts are never overwritten. */
    public function publishForCycle(int $cycleId): int
    {
        return DB::transaction(function () use ($cycleId): int {
            $cycle = DB::table('award_cycles')->where('id', $cycleId)->lockForUpdate()->first();
            if ($cycle === null || ! in_array($cycle->status, ['draft', 'published'], true)) {
                throw new InvalidArgumentException('Choose an existing draft or published cycle; historical cycles cannot receive a new current terms version.');
            }
            $ar = $this->body('ar');
            $en = $this->body('en');
            $hash = hash('sha256', $ar."\n".$en);
            $latest = DB::table('policy_versions')->where('cycle_id', $cycleId)->where('code', self::CODE)->orderByDesc('version_number')->lockForUpdate()->first();
            $current = $this->current($cycleId);
            if ($current !== null && $current->content_hash === $hash) {
                return (int) $current->id;
            }

            return DB::table('policy_versions')->insertGetId([
                'cycle_id' => $cycleId, 'code' => self::CODE, 'version_number' => ($latest?->version_number ?? 0) + 1,
                'title_ar' => $this->content()['ar']['title'], 'body_ar' => $ar, 'body_en' => $en, 'content_hash' => $hash,
                'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function current(int $cycleId): ?object
    {
        return DB::table('policy_versions')->where('cycle_id', $cycleId)->where('code', self::CODE)->where('status', 'published')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->orderByDesc('version_number')->first();
    }
}
