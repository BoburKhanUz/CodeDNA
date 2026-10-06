<?php

declare(strict_types=1);

namespace App\Services\Dna;

use InvalidArgumentException;

/**
 * Maps a measured ratio onto the 0–1 score scale
 * (docs/architecture/dna-scoring-v1.md#normalization). Bounded: the result
 * is always between 0 and FixedPoint::ONE, whatever the input.
 */
final class Normalizer
{
    /**
     * "Lower is better", linear between two thresholds, exact on the
     * rational value numerator / denominator (no rounding before the final
     * half-up rounding):
     *
     *     value <= best   -> 1
     *     value >= worst  -> 0
     *     otherwise       -> (worst − value) / (worst − best)
     *
     * @param  int  $best  threshold in fixed-point units
     * @param  int  $worst  threshold in fixed-point units, greater than $best
     * @return int score in fixed-point units
     */
    public static function lowerIsBetter(int $numerator, int $denominator, int $best, int $worst): int
    {
        if ($numerator < 0 || $denominator <= 0 || $best < 0 || $worst <= $best) {
            throw new InvalidArgumentException('Invalid normalization input.');
        }

        // value × denominator in units, compared without dividing.
        $scaledValue = FixedPoint::multiply($numerator, FixedPoint::ONE);
        if ($scaledValue <= FixedPoint::multiply($best, $denominator)) {
            return FixedPoint::ONE;
        }
        $headroom = FixedPoint::multiply($worst, $denominator) - $scaledValue;
        if ($headroom <= 0) {
            return 0;
        }

        return FixedPoint::divide(FixedPoint::multiply($headroom, FixedPoint::ONE), FixedPoint::multiply($worst - $best, $denominator));
    }
}
