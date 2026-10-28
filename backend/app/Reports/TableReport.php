<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * One exported table: its title, the scope it was produced for (filters,
 * search, sort, cycle, in readable Arabic), the generation time, its
 * columns and every matching row, already formatted as display text.
 * Only the table's own columns: never action buttons or internal fields.
 */
final class TableReport
{
    /**
     * @param  list<array{key: string, label: string, width?: int}>  $columns
     * @param  list<array<string, string|int|null>>  $rows
     * @param  list<array{0: string, 1: string}>  $scope  [label, value]
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $scope,
        public readonly Carbon $generatedAt,
    ) {}

    /** The generation time as shown, in Damascus. */
    public function generatedLabel(): string
    {
        return Formats::dateTime($this->generatedAt->toIso8601ZuluString());
    }
}
