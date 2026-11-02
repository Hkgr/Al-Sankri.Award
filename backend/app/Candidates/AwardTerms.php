<?php

namespace App\Candidates;

use Illuminate\Support\Facades\DB;

class AwardTerms
{
    public const CODE = 'general_application_terms';

    /** @return array<string, mixed> */
    public function content(): array
    {
        return json_decode(file_get_contents(resource_path('award/terms-2026-11-02.json')), true, flags: JSON_THROW_ON_ERROR);
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
        $ar = $this->body('ar');
        $en = $this->body('en');
        $hash = hash('sha256', $ar."\n".$en);
        $latest = DB::table('policy_versions')->where('cycle_id', $cycleId)->where('code', self::CODE)->orderByDesc('version_number')->lockForUpdate()->first();
        if ($latest !== null && $latest->content_hash === $hash && $latest->status === 'published') {
            return (int) $latest->id;
        }

        return DB::table('policy_versions')->insertGetId([
            'cycle_id' => $cycleId, 'code' => self::CODE, 'version_number' => ($latest?->version_number ?? 0) + 1,
            'title_ar' => $this->content()['ar']['title'], 'body_ar' => $ar, 'body_en' => $en, 'content_hash' => $hash,
            'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function current(int $cycleId): ?object
    {
        return DB::table('policy_versions')->where('cycle_id', $cycleId)->where('code', self::CODE)->where('status', 'published')->orderByDesc('version_number')->first();
    }
}
