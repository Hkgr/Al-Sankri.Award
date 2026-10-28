<?php

namespace App\Reports;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Server-side PDFs (mPDF): the application card and table reports, Arabic
 * right-to-left with full OpenType shaping.
 *
 * The site's Rayat font is OpenType with PostScript (CFF) outlines, which
 * mPDF cannot embed; PDFs therefore use the bundled Tajawal (Arabic and
 * Latin, SIL Open Font License, resources/fonts/tajawal/OFL.txt). Noto
 * Sans/Naskh Arabic were tried first: mPDF cannot read their current
 * OpenType layout tables. The website font is unchanged.
 *
 * Only escaped text is rendered (Blade); no remote resource is fetched: the
 * only image is the supplied logo, read from resources/reports.
 */
final class PdfWriter
{
    private const FONT = 'tajawal';

    /**
     * A table report: landscape and larger paper as the columns grow; the
     * heading row repeats on every page, long cells wrap.
     */
    public function table(TableReport $report): string
    {
        $columns = count($report->columns);
        $format = $columns > 9 ? 'A3-L' : ($columns > 5 ? 'A4-L' : 'A4');

        return $this->render(view('reports.table', ['report' => $report])->render(), $format, $report->title, $report->generatedLabel());
    }

    /**
     * The application card (ApplicationCard::sections).
     *
     * @param  array<string, mixed>  $card
     */
    public function application(array $card, string $generatedLabel): string
    {
        return $this->render(view('reports.application', ['card' => $card])->render(), 'A4', 'بطاقة الترشح', $generatedLabel);
    }

    private function render(string $html, string $format, string $title, string $generatedLabel): string
    {
        $temp = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($temp);
        $config = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'tempDir' => $temp,
            'fontDir' => [...$config['fontDir'], resource_path('fonts/tajawal')],
            'fontdata' => $fonts['fontdata'] + [
                self::FONT => ['R' => 'Tajawal-Regular.ttf', 'B' => 'Tajawal-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            ],
            'default_font' => self::FONT,
            'directionality' => 'rtl',
            // Any glyph Tajawal lacks comes from mPDF's DejaVu Sans.
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusans'],
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            // Wide tables wrap instead of shrinking their text.
            'shrink_tables_to_fit' => 1,
            'margin_top' => 30,
            'margin_bottom' => 18,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('جائزة الدكتور عبد القادر سنكري');
        $mpdf->SetCreator('منصة الجائزة');
        $mpdf->showImageErrors = false;

        $mpdf->SetHTMLHeader(view('reports.header', ['title' => $title, 'logo' => resource_path('reports/logo-horizontal-light-bg.svg')])->render());
        $mpdf->SetHTMLFooter(view('reports.footer', ['generated' => $generatedLabel])->render());
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
