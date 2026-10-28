<?php

namespace App\Reports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A genuine XLSX workbook for one TableReport (OpenSpout, streamed to a
 * private temporary file): a right-to-left sheet, the report's scope above
 * the table, a styled heading row that stays frozen and carries filters,
 * and suitable column widths.
 *
 * Every value is written as an explicit text cell (StringCell), so phone
 * numbers, references and identifiers keep their exact form and nothing a
 * user typed (even text starting with =, +, - or @) can become a formula.
 * Only counts the system computed are numbers.
 */
final class XlsxWriter
{
    private const GREEN = '14413D';

    private const LIGHT = 'F4F7FC';

    public function write(TableReport $report, string $path): void
    {
        $options = new Options;
        $options->DEFAULT_COLUMN_WIDTH = 16;
        foreach ($report->columns as $index => $column) {
            $options->setColumnWidth((float) ($column['width'] ?? 18), $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(mb_substr($report->title, 0, 31));

            $title = (new Style)->setFontBold()->setFontSize(14)->setFontColor(self::GREEN);
            $label = (new Style)->setFontBold()->setFontColor(self::GREEN);
            $writer->addRow(new Row([new StringCell($report->title, $title)]));
            foreach ($report->scope as [$name, $value]) {
                $writer->addRow(new Row([new StringCell($name, $label), new StringCell($value, null)]));
            }
            $writer->addRow(new Row([new StringCell('وقت الإنشاء (بتوقيت دمشق)', $label), new StringCell($report->generatedLabel(), null)]));
            $writer->addRow(new Row([new StringCell('عدد الصفوف', $label), new NumericCell(count($report->rows), null)]));
            $writer->addRow(new Row([]));

            $headerRow = count($report->scope) + 4;
            $heading = (new Style)->setFontBold()->setFontColor(self::LIGHT)->setBackgroundColor(self::GREEN)
                ->setCellAlignment(CellAlignment::RIGHT)->setShouldWrapText();
            $writer->addRow(new Row(array_map(fn (array $column): Cell => new StringCell($column['label'], $heading), $report->columns)));

            $body = (new Style)->setShouldWrapText()->setCellAlignment(CellAlignment::RIGHT)->setCellVerticalAlignment(CellVerticalAlignment::TOP);
            foreach ($report->rows as $row) {
                $writer->addRow(new Row(array_map(function (array $column) use ($row, $body): Cell {
                    $value = $row[$column['key']] ?? '';

                    return is_int($value) ? new NumericCell($value, $body) : new StringCell((string) $value, $body);
                }, $report->columns)));
            }

            $sheet->setSheetView((new SheetView)->setRightToLeft(true)->setFreezeRow($headerRow + 1));
            $sheet->setAutoFilter(new AutoFilter(0, $headerRow, max(0, count($report->columns) - 1), $headerRow + max(1, count($report->rows))));
        } finally {
            $writer->close();
        }
    }
}
