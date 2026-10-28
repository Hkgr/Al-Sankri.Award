<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * International phone numbers, validated with libphonenumber's metadata and
 * stored as E.164 ("+963912345678"). A number is only checked for a valid
 * format for its country: it is never treated as verified or as an identity.
 */
final class PhoneNumbers
{
    /**
     * @var array<string, int>|null
     */
    private static ?array $callingCodes = null;

    /**
     * Calling codes for the countries in Countries, in the same order.
     * Territories without a numbering plan of their own (Antarctica, for
     * example) are left out.
     *
     * @return array<string, int> ISO code => country calling code
     */
    public static function callingCodes(): array
    {
        if (self::$callingCodes !== null) {
            return self::$callingCodes;
        }

        $util = PhoneNumberUtil::getInstance();
        $codes = [];

        foreach (Countries::codes() as $region) {
            $code = $util->getCountryCodeForRegion($region);
            if ($code > 0) {
                $codes[$region] = $code;
            }
        }

        return self::$callingCodes = $codes;
    }

    public static function supportsRegion(mixed $region): bool
    {
        return is_string($region) && isset(self::callingCodes()[$region]);
    }

    /**
     * The E.164 form of a number written in international form (with its
     * own "+" or "00" prefix), or null when it is not a valid number.
     */
    public static function internationalToE164(mixed $number): ?string
    {
        if (! is_string($number) || strlen($number) > 32) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();
        $number = preg_replace('/^00/', '+', trim($number));
        if (! str_starts_with((string) $number, '+')) {
            return null;
        }
        try {
            $parsed = $util->parse((string) $number, 'ZZ');
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($parsed) ? $util->format($parsed, PhoneNumberFormat::E164) : null;
    }

    /**
     * The E.164 form of a number entered for the given country, or null when
     * it is not a valid number. A number typed with its own "+" or "00"
     * prefix must still belong to the selected country.
     */
    public static function toE164(mixed $region, mixed $number): ?string
    {
        if (! self::supportsRegion($region) || ! is_string($number) || $number === '' || strlen($number) > 32) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();
        $number = preg_replace('/^00/', '+', $number);

        try {
            $parsed = $util->parse($number, $region);
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumberForRegion($parsed, $region)) {
            return null;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
