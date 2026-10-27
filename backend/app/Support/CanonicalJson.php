<?php

namespace App\Support;

/**
 * The canonical JSON form used for content hashes (application_versions
 * .content_hash: SHA-256, lowercase hex, of the canonical snapshot): object
 * keys sorted at every level, list order kept, UTF-8 and slashes unescaped.
 * The same data always gives the same text, whatever order a database
 * returns JSON keys in.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
