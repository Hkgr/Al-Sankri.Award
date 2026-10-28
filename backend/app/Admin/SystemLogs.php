<?php

namespace App\Admin;

use Illuminate\Support\Carbon;

/**
 * «الأخطاء التقنية»: a read-only, sanitized view of the application's own
 * Laravel log files. Not a server console: no shell, no arbitrary path,
 * no deletion.
 *
 * Sources are decided by the server only: the files of the configured
 * `single`/`daily` channels that lie directly in storage/logs (symlinks and
 * anything outside are ignored). A request names a source by an id taken
 * from that list, never a path.
 *
 * Files are read backwards in bounded chunks (newest entries first) with a
 * byte budget per request, so a large log is never loaded whole; the byte
 * cursor continues from where the last page stopped. Multiline entries
 * (stack traces) stay one entry.
 *
 * Only allowlisted fields leave this class: time, level, the exception
 * class, the file name and line where it was thrown, the request id and the
 * user id. Messages, stack traces, SQL and request data are never
 * returned: a free-text entry gets a generic label instead, because it can
 * contain a mail (with reset or verification links), bindings or secrets.
 * Runtime entries carry no cycle: they are global.
 */
class SystemLogs
{
    public const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public const PAGE_ENTRIES = 50;

    /** Bytes read for one page. */
    public const PAGE_SCAN_BYTES = 1_048_576;

    /** Bytes read for one export. */
    public const EXPORT_SCAN_BYTES = 33_554_432;

    private const CHUNK = 65_536;

    private const HEADER = '/(?:^|(?<=\n))\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([A-Za-z0-9_.-]{1,40})\.([A-Z]{3,9}): /';

    private const CLASS_LABELS = [
        'QueryException' => 'خطأ في قاعدة البيانات',
        'PDOException' => 'خطأ في الاتصال بقاعدة البيانات',
        'ErrorException' => 'خطأ في تنفيذ الشيفرة',
        'TransportException' => 'تعذّر إرسال بريد',
        'TypeError' => 'خطأ في نوع قيمة',
        'ValueError' => 'قيمة غير صالحة',
        'ModelNotFoundException' => 'سجل غير موجود',
        'TokenMismatchException' => 'انتهاء صلاحية رمز الحماية',
        'ViewException' => 'خطأ في عرض صفحة',
    ];

    /**
     * The view's filters (shared by the view and its exports).
     *
     * @return array<string, list<mixed>>
     */
    public static function filterRules(): array
    {
        return [
            'source' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'level' => ['nullable', 'in:'.implode(',', self::LEVELS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The readable sources, newest first, and what they cover.
     *
     * @return array{sources: list<array<string, mixed>>, coverage: array<string, mixed>}
     */
    public function sources(): array
    {
        $directory = realpath(storage_path('logs'));
        $patterns = [];
        $channels = [];
        $retention = null;

        foreach ($this->channels() as $name => $channel) {
            $path = $channel['path'] ?? null;
            if (! is_string($path) || ! in_array($channel['driver'] ?? null, ['single', 'daily'], true) || $directory === false) {
                continue;
            }
            $parent = realpath(dirname($path));
            if ($parent !== $directory) {
                continue;
            }
            $base = preg_quote(pathinfo($path, PATHINFO_FILENAME), '/');
            $extension = preg_quote(pathinfo($path, PATHINFO_EXTENSION), '/');
            $channels[] = $name;
            if ($channel['driver'] === 'daily') {
                $patterns[] = "/^{$base}-\\d{4}-\\d{2}-\\d{2}\\.{$extension}$/";
                $retention = (int) ($channel['days'] ?? $channel['max_files'] ?? 14);
            } else {
                $patterns[] = '/^'.preg_quote(basename($path), '/').'$/';
            }
        }

        $sources = [];
        foreach ($directory === false ? [] : (scandir($directory) ?: []) as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $allowed = array_filter($patterns, fn (string $pattern): bool => preg_match($pattern, $name) === 1) !== [];
            if (! $allowed || ! is_file($path) || is_link($path) || ! is_readable($path)) {
                continue;
            }
            $sources[] = ['id' => $name, 'size_bytes' => (int) filesize($path), 'modified_at' => Carbon::createFromTimestampUTC((int) filemtime($path))->toIso8601ZuluString()];
        }
        usort($sources, fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']) ?: strcmp($b['id'], $a['id']));

        return ['sources' => $sources, 'coverage' => [
            'channels' => array_values(array_unique($channels)),
            'retention_days' => $retention,
            'oldest_file' => $sources === [] ? null : end($sources)['id'],
        ]];
    }

    /**
     * One page of entries of one source, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null Null for an unknown source.
     */
    public function page(string $sourceId, array $filters, int $limit = self::PAGE_ENTRIES, int $budget = self::PAGE_SCAN_BYTES): ?array
    {
        $path = $this->path($sourceId);
        if ($path === null) {
            return null;
        }

        $size = (int) filesize($path);
        $end = isset($filters['cursor']) ? min((int) $filters['cursor'], $size) : $size;
        $from = empty($filters['from']) ? null : Carbon::parse($filters['from'], 'Asia/Damascus')->startOfDay()->utc();
        $to = empty($filters['to']) ? null : Carbon::parse($filters['to'], 'Asia/Damascus')->addDay()->startOfDay()->utc();
        $level = $filters['level'] ?? null;

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $entries = [];
        $buffer = '';
        $position = $end;
        $scanned = 0;
        $cursor = $end;
        $reachedOlder = false;
        $skipped = 0;

        try {
            while ($position > 0 && count($entries) < $limit && $scanned < $budget && ! $reachedOlder) {
                $read = (int) min(self::CHUNK, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = (string) fread($handle, $read).$buffer;
                $scanned += $read;

                preg_match_all(self::HEADER, $buffer, $matches, PREG_OFFSET_CAPTURE);
                $starts = array_map(fn (array $match): int => (int) $match[1], $matches[0]);
                // A header at the very start of the buffer only counts at the file's start
                // or right after a line break (otherwise a chunk boundary cut the line).
                if ($starts !== [] && $starts[0] === 0 && $position > 0) {
                    fseek($handle, $position - 1);
                    if (fread($handle, 1) !== "\n") {
                        array_shift($starts);
                    }
                }
                if ($starts === []) {
                    continue;
                }

                $taken = null;
                for ($index = count($starts) - 1; $index >= 0 && count($entries) < $limit; $index--) {
                    $start = $starts[$index];
                    $stop = $starts[$index + 1] ?? strlen($buffer);
                    $entry = $this->parse(substr($buffer, $start, min($stop - $start, 8192)), $position + $start);
                    $taken = $start;
                    if ($entry === null) {
                        continue;
                    }
                    if ($from !== null && $entry['time'] !== null && Carbon::parse($entry['time'])->lt($from)) {
                        $reachedOlder = true;
                        break;
                    }
                    $outside = ($to !== null && $entry['time'] !== null && Carbon::parse($entry['time'])->gte($to)) || ($level !== null && $entry['level'] !== $level);
                    if ($outside) {
                        $skipped++;

                        continue;
                    }
                    $entries[] = $entry;
                }
                if ($taken !== null) {
                    $cursor = $position + $taken;
                    $buffer = substr($buffer, 0, $taken);
                }
            }

            // A single segment longer than the budget, with no entry start: step over it.
            if ($entries === [] && $position > 0 && $scanned >= $budget && $cursor === $end) {
                $cursor = $position;
            }
            // At the file's start with no entry left unread: done.
            if ($position === 0 && preg_match(self::HEADER, $buffer) !== 1) {
                $cursor = 0;
            }
        } finally {
            fclose($handle);
        }

        $done = $reachedOlder || $cursor === 0;

        return [
            'source' => ['id' => $sourceId, 'size_bytes' => $size],
            'entries' => $entries,
            'next_cursor' => $done ? null : $cursor,
            'window' => [
                'from_byte' => $cursor,
                'to_byte' => $end,
                'scanned_bytes' => $scanned,
                'newest' => $entries[0]['time'] ?? null,
                'oldest' => $entries === [] ? null : end($entries)['time'],
                'skipped_by_filters' => $skipped,
                'reached_start' => $cursor === 0,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function channels(): array
    {
        $all = (array) config('logging.channels', []);
        $default = (string) config('logging.default');
        $names = ($all[$default]['driver'] ?? null) === 'stack' ? (array) ($all[$default]['channels'] ?? []) : [$default];

        return array_filter(array_intersect_key($all, array_flip(array_map('trim', $names))), 'is_array');
    }

    /**
     * The file of an allowlisted source id, or null.
     */
    private function path(string $sourceId): ?string
    {
        foreach ($this->sources()['sources'] as $source) {
            if ($source['id'] === $sourceId) {
                $path = realpath(storage_path('logs').DIRECTORY_SEPARATOR.$source['id']);

                return $path !== false && dirname($path) === realpath(storage_path('logs')) && ! is_link($path) ? $path : null;
            }
        }

        return null;
    }

    /**
     * The allowlisted fields of one entry (at most its first 8 KiB are looked at).
     *
     * @return array<string, mixed>|null
     */
    private function parse(string $text, int $offset): ?array
    {
        $line = strtok($text, "\n");
        if ($line === false || preg_match(self::HEADER, $line, $header) !== 1 || ! in_array($header[3], self::LEVELS, true)) {
            return null;
        }

        try {
            $time = Carbon::parse($header[1], (string) config('app.timezone', 'UTC'))->utc()->toIso8601ZuluString();
        } catch (\Throwable) {
            $time = null;
        }

        $class = null;
        // Codes are numbers or SQLSTATE values (HY000, 23000).
        if (preg_match('/"exception":"\[object\] \(([A-Za-z_][A-Za-z0-9_\\\\]{0,200})\(code: [A-Za-z0-9-]{1,12}\)/', $line, $match) === 1) {
            $class = str_replace('\\\\', '\\', $match[1]);
        }
        $location = null;
        if ($class !== null && preg_match('/ at ([^"]{1,500}?):(\d{1,7})\)/', $line, $match) === 1) {
            $file = basename(str_replace('\\\\', '/', str_replace('\\', '/', $match[1])));
            $location = preg_match('/^[A-Za-z0-9_.-]{1,120}\.php$/', $file) === 1 ? "{$file}:{$match[2]}" : null;
        }
        $requestId = preg_match('/"request_id":"([0-9a-f-]{36})"/', $text, $match) === 1 ? $match[1] : null;
        $userId = preg_match('/"userId":(\d{1,19})/', $line, $match) === 1 ? (int) $match[1] : null;

        $short = $class === null ? null : substr((string) strrchr('\\'.$class, '\\'), 1);

        return [
            'offset' => $offset,
            'time' => $time,
            'level' => $header[3],
            'category' => $class ?? 'log_message',
            'summary' => $class === null
                ? 'إدخال سجل نصي؛ محتواه مخفي لأسباب أمنية'
                : (self::CLASS_LABELS[$short] ?? 'استثناء غير معالَج').": {$short}",
            'location' => $location,
            'request_id' => $requestId,
            'user_id' => $userId,
        ];
    }
}
