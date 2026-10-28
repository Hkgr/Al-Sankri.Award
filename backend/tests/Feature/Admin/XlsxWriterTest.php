<?php

namespace Tests\Feature\Admin;

use App\Reports\TableReport;
use App\Reports\XlsxWriter;
use Illuminate\Support\Carbon;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class XlsxWriterTest extends TestCase
{
    public function test_the_filter_starts_on_the_heading_row_ends_on_the_last_data_row_and_the_pane_freezes_below_it(): void
    {
        // Title (1), two scope rows (2–3), time (4), count (5), blank (6),
        // heading (7), three data rows (8–10).
        $xml = $this->sheetXml($this->report([['الدورة', 'دورة'], ['الترتيب', 'الاسم']], 3));

        $this->assertSame('A7:D10', $this->attribute($xml, 'autoFilter', 'ref'));
        $this->assertSame('7', $this->attribute($xml, 'pane', 'ySplit'));
        $this->assertSame('A8', $this->attribute($xml, 'pane', 'topLeftCell'));
        $this->assertSame(['عمود 1', 'عمود 2', 'عمود 3', 'عمود 4'], $this->rowText($xml, 7));
        $this->assertSame(['قيمة 3-1', 'قيمة 3-2', 'قيمة 3-3', 'قيمة 3-4'], $this->rowText($xml, 10));
        $this->assertSame([], $this->rowText($xml, 11));
        $this->assertSame([], $this->rowText($xml, 6), 'the blank separator row');
    }

    public function test_an_empty_report_filters_only_its_heading_without_inventing_a_data_row(): void
    {
        // No scope rows: title (1), time (2), count (3), blank (4), heading (5).
        $xml = $this->sheetXml($this->report([], 0));

        $this->assertSame('A5:D5', $this->attribute($xml, 'autoFilter', 'ref'));
        $this->assertSame('5', $this->attribute($xml, 'pane', 'ySplit'));
        $this->assertSame('A6', $this->attribute($xml, 'pane', 'topLeftCell'));
        $this->assertSame(['عمود 1', 'عمود 2', 'عمود 3', 'عمود 4'], $this->rowText($xml, 5));
        $this->assertSame([], $this->rowText($xml, 6));
    }

    public function test_a_sheet_name_is_valid_for_any_title(): void
    {
        $this->assertSame('أعضاء لجنة التراث الفنون أ ب', XlsxWriter::sheetName('أعضاء لجنة: التراث/الفنون [أ]*?\\ب'));
        $this->assertSame('تقرير', XlsxWriter::sheetName("'/[]:*?\\'"));
        $this->assertSame('تقرير', XlsxWriter::sheetName('History'));
        $this->assertSame('a b', XlsxWriter::sheetName("'a'b'"));
        $long = XlsxWriter::sheetName(str_repeat('لجنة طويلة ', 10));
        $this->assertLessThanOrEqual(31, mb_strlen($long));
        $this->assertNotSame('', $long);

        // OpenSpout itself accepts every one of them (it throws otherwise).
        $path = tempnam(sys_get_temp_dir(), 'sheet');
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            foreach (['أعضاء لجنة: التراث/الفنون [أ]*?\\ب', "'/[]:*?\\'", str_repeat('ب', 40)."'"] as $index => $title) {
                $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName(XlsxWriter::sheetName($title));
                $this->assertSame(XlsxWriter::sheetName($title), $sheet->getName());
            }
        } finally {
            $writer->close();
            @unlink($path);
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $scope
     */
    private function report(array $scope, int $rows): TableReport
    {
        $columns = array_map(fn (int $n): array => ['key' => "c{$n}", 'label' => "عمود {$n}"], range(1, 4));
        $data = array_map(fn (int $row): array => array_combine(
            array_map(fn (int $n): string => "c{$n}", range(1, 4)),
            array_map(fn (int $n): string => "قيمة {$row}-{$n}", range(1, 4)),
        ), $rows === 0 ? [] : range(1, $rows));

        return new TableReport('test', 'تقرير اختبار', $columns, $data, $scope, Carbon::parse('2026-10-29T08:00:00Z'));
    }

    private function sheetXml(TableReport $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        try {
            (new XlsxWriter)->write($report, $path);
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        return $xml;
    }

    private function attribute(string $xml, string $element, string $name): ?string
    {
        return preg_match('/<'.$element.'\b[^>]*\b'.$name.'="([^"]*)"/', $xml, $match) === 1 ? $match[1] : null;
    }

    /**
     * The inline text of every cell in one row, in column order.
     *
     * @return list<string>
     */
    private function rowText(string $xml, int $row): array
    {
        preg_match_all('/<c r="[A-Z]+'.$row.'"[^>]*>(?:<is><t[^>]*>(.*?)<\/t><\/is>|<v>(.*?)<\/v>)?<\/c>/s', $xml, $matches, PREG_SET_ORDER);

        return array_map(fn (array $cell): string => html_entity_decode($cell[1] !== '' ? $cell[1] : ($cell[2] ?? '')), $matches);
    }
}
