<?php

namespace App\Reports;

/**
 * The synchronous export limits, in one place. Above them an export is
 * refused (never truncated) and the administrator narrows the filters.
 * Conservative on purpose: both formats are built inside the request.
 */
final class ExportLimits
{
    /** Rows in one table PDF. */
    public const PDF_ROWS = 1_000;

    /** Rows in one XLSX file. */
    public const XLSX_ROWS = 10_000;

    public const FORMATS = ['xlsx', 'pdf'];

    public static function for(string $format): int
    {
        return $format === 'pdf' ? self::PDF_ROWS : self::XLSX_ROWS;
    }
}
