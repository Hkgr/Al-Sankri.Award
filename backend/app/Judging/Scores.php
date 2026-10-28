<?php

namespace App\Judging;

/**
 * One mark out of 100. Stored as a two-decimal string (never a float) and
 * shown without a trailing «.00». The committee decision is the arithmetic
 * mean of the finalized marks, rounded half up to two decimals.
 */
final class Scores
{
    public static function normalize(float|int|string $score): string
    {
        return number_format((float) $score, 2, '.', '');
    }

    public static function present(string $score): string
    {
        $normalized = self::normalize($score);

        return str_ends_with($normalized, '.00') ? substr($normalized, 0, -3) : rtrim(rtrim($normalized, '0'), '.');
    }

    /**
     * @param  list<string>  $scores  Two-decimal marks.
     */
    public static function average(array $scores): string
    {
        $count = count($scores);
        $cents = 0;
        foreach ($scores as $score) {
            $cents += (int) str_replace('.', '', self::normalize($score));
        }

        $rounded = intdiv($cents + intdiv($count, 2), $count);

        return self::present(intdiv($rounded, 100).'.'.str_pad((string) ($rounded % 100), 2, '0', STR_PAD_LEFT));
    }
}
