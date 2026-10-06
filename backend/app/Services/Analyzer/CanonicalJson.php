<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use InvalidArgumentException;
use stdClass;

/**
 * Canonical JSON exactly as the analyzer produces it (analyzer/app/canonical.py:
 * Python json.dumps with sort_keys=True, separators=(",", ":"),
 * ensure_ascii=False, allow_nan=False), so Laravel can recompute result_hash
 * from a response and compare (internal analyzer contract, section 4).
 *
 * Input must come from json_decode(..., associative: false): JSON objects are
 * stdClass (an empty object stays {}), arrays are lists. Keys are sorted by
 * Unicode code point (byte order of UTF-8). Floats use Python's repr():
 * shortest round-trip digits, positional for 1e-4 <= |x| < 1e16, otherwise
 * exponent form such as 1e-05 or 1.5e+16.
 */
final class CanonicalJson
{
    private const STRING_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => json_encode($value, self::STRING_FLAGS),
            $value instanceof stdClass => self::object($value),
            is_array($value) && array_is_list($value) => '['.implode(',', array_map(self::encode(...), $value)).']',
            default => throw new InvalidArgumentException('Only json_decode(associative: false) output can be canonicalized.'),
        };
    }

    /**
     * SHA-256 of the canonical JSON.
     */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function object(stdClass $object): string
    {
        // PHP turns numeric-string keys ("10") into integers in arrays, so
        // keys are kept as strings in a list of pairs.
        $members = [];
        foreach (get_object_vars($object) as $key => $member) {
            $members[] = [(string) $key, $member];
        }
        usort($members, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $parts = [];
        foreach ($members as [$key, $member]) {
            $parts[] = json_encode($key, self::STRING_FLAGS).':'.self::encode($member);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * Python's repr() of a finite float.
     */
    public static function float(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new InvalidArgumentException('NaN and infinity are not valid JSON.');
        }
        if ($value == 0.0) {
            return (fdiv(1.0, $value) < 0) ? '-0.0' : '0.0';
        }

        // Shortest round-trip digits (PHP's serialize_precision = -1 algorithm).
        $shortest = var_export($value, true);
        $sign = '';
        if ($shortest[0] === '-') {
            $sign = '-';
            $shortest = substr($shortest, 1);
        }
        $exponent = 0;
        if (preg_match('/^([0-9.]+)[eE]([+-]?[0-9]+)$/', $shortest, $m) === 1) {
            $shortest = $m[1];
            $exponent = (int) $m[2];
        }
        [$integer, $fraction] = array_pad(explode('.', $shortest, 2), 2, '');
        $all = $integer.$fraction;
        $leadingZeros = strlen($all) - strlen(ltrim($all, '0'));
        $digits = rtrim(ltrim($all, '0'), '0');
        // value = d.ddd × 10^$scientific
        $scientific = strlen($integer) - 1 - $leadingZeros + $exponent;

        if ($scientific >= -4 && $scientific < 16) {
            if ($scientific >= 0) {
                $whole = str_pad(substr($digits, 0, $scientific + 1), $scientific + 1, '0');
                $rest = substr($digits, $scientific + 1);

                return $sign.$whole.'.'.($rest === '' ? '0' : $rest);
            }

            return $sign.'0.'.str_repeat('0', -$scientific - 1).$digits;
        }

        $mantissa = $digits[0].(strlen($digits) > 1 ? '.'.substr($digits, 1) : '');

        return $sign.$mantissa.'e'.($scientific < 0 ? '-' : '+').str_pad((string) abs($scientific), 2, '0', STR_PAD_LEFT);
    }
}
