<?php

declare(strict_types=1);

namespace App\Services\Dna;

use InvalidArgumentException;
use OverflowException;

/**
 * Integer fixed-point arithmetic for DNA scoring (docs/architecture/dna-scoring-v1.md#arithmetic).
 * A value is an integer number of 1/10 000 units: 1.0 is 10 000, 0.0125 is
 * 125. No floating point is used anywhere in scoring, so results do not
 * depend on the platform's float rounding (ADR-004).
 *
 * Every division rounds half up to the nearest unit (4 decimal places).
 * Every operand is non-negative, so half up and "half away from zero" agree.
 */
final class FixedPoint
{
    /** 1.0 in units; 4 decimal places. */
    public const ONE = 10_000;

    public const PLACES = 4;

    /**
     * round(numerator / denominator) half up, as an integer.
     */
    public static function divide(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Fixed-point division needs a non-negative numerator and a positive denominator.');
        }

        // floor((2n + d) / 2d) = round half up of n / d.
        return intdiv(self::add(self::multiply(2, $numerator), $denominator), self::multiply(2, $denominator));
    }

    /**
     * Overflow-checked integer multiplication (PHP would silently turn the
     * result into a float).
     */
    public static function multiply(int $a, int $b): int
    {
        $product = $a * $b;
        if (! is_int($product)) {
            throw new OverflowException('Fixed-point overflow.');
        }

        return $product;
    }

    public static function add(int $a, int $b): int
    {
        $sum = $a + $b;
        if (! is_int($sum)) {
            throw new OverflowException('Fixed-point overflow.');
        }

        return $sum;
    }

    /**
     * Parses a decimal literal with at most 4 decimal places, e.g. "0.40".
     */
    public static function parse(string $decimal): int
    {
        if (preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,4}))?$/', $decimal, $match) !== 1) {
            throw new InvalidArgumentException("Not a fixed-point decimal: {$decimal}");
        }

        return (int) $match[1] * self::ONE + (int) str_pad($match[2] ?? '', self::PLACES, '0');
    }

    /**
     * Units as a decimal string with exactly 4 places, e.g. 8125 -> "0.8125".
     */
    public static function format(int $units): string
    {
        if ($units < 0) {
            throw new InvalidArgumentException('Scores are never negative.');
        }

        return intdiv($units, self::ONE).'.'.str_pad((string) ($units % self::ONE), self::PLACES, '0', STR_PAD_LEFT);
    }
}
