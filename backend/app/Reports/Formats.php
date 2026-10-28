<?php

namespace App\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use IntlDateFormatter;

/**
 * Display formats shared by the exports, matching the dashboard: dates and
 * times in Damascus with Latin digits, exact byte sizes, readable labels.
 */
final class Formats
{
    public const TIME_ZONE = 'Asia/Damascus';

    private const LOCALE = 'ar_SY@numbers=latn;calendar=gregorian';

    public static function dateTime(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        $formatter = new IntlDateFormatter(self::LOCALE, IntlDateFormatter::LONG, IntlDateFormatter::SHORT, self::TIME_ZONE, IntlDateFormatter::GREGORIAN);

        return (string) $formatter->format(Carbon::parse($iso)->getTimestamp());
    }

    public static function date(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $formatter = new IntlDateFormatter(self::LOCALE, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN);

        return (string) $formatter->format(Carbon::parse($date, 'UTC')->getTimestamp());
    }

    public static function bytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? number_format($bytes / 1_048_576, 1).' ميغابايت'
            : number_format(max(1, (int) round($bytes / 1024))).' كيلوبايت';
    }

    /**
     * One escaped value for a PDF cell. A value written only in Latin
     * characters (phone numbers, emails, links, references, codes) is
     * isolated left-to-right, so a leading "+" or a closing parenthesis
     * stays where it belongs inside the Arabic page. Line breaks are kept.
     */
    public static function cell(string|int|null $value): HtmlString
    {
        $text = (string) ($value ?? '');
        // Long unbroken tokens (links, hashes) may break after a separator or
        // every 32 characters (zero-width space), instead of shrinking the table.
        $breakable = preg_replace_callback('/\S{33,}/u', fn (array $token): string => (string) preg_replace(
            ['/([\/?&=._-])/u', '/([^\/?&=._\x{200B}-]{32})/u'], ["$1\u{200B}", "$1\u{200B}"], $token[0],
        ), $text) ?? $text;
        $escaped = nl2br(e($breakable), false);

        // The direction of the first strong letter, as dir="auto" would choose
        // (mPDF has no "auto"); letterless ASCII such as a phone number is LTR.
        $ltr = preg_match('/\p{L}/u', $text, $letter) === 1
            ? preg_match('/[^\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}]/u', $letter[0]) === 1
            : $text !== '' && preg_match('/^[\x20-\x7E\r\n]+$/', $text) === 1;

        return new HtmlString($ltr ? "<bdi dir=\"ltr\">{$escaped}</bdi>" : $escaped);
    }

    /**
     * A safe download name: the report code, a date and the extension.
     */
    public static function filename(string $code, string $extension, ?Carbon $at = null): string
    {
        $stamp = ($at ?? now())->copy()->setTimezone(self::TIME_ZONE)->format('Y-m-d-His');

        return preg_replace('/[^a-z0-9-]/', '-', strtolower($code))."-{$stamp}.{$extension}";
    }
}
