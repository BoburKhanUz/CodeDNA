<?php

declare(strict_types=1);

namespace App\Services\Insights\Evidence;

use App\Enums\Insights\InsightFailure;
use App\Services\Dna\FixedPoint;
use App\Services\Insights\InsightException;
use InvalidArgumentException;

/**
 * The allowlist the evidence builders use: every fact is re-formatted from a
 * checked type, never copied as free text. A stored value that fails a check
 * rejects the evidence (EVIDENCE_INVALID) instead of being passed on.
 */
final class Facts
{
    public const ID_PATTERN = '^[a-z_]{1,24}:[A-Za-z0-9_.:-]{1,96}$';

    /**
     * @param  array<string, mixed>  $facts
     * @return array{id: string, kind: string, label: string, description: string, facts: array<string, mixed>}
     */
    public static function item(string $id, string $label, string $description, array $facts): array
    {
        if (preg_match('/'.self::ID_PATTERN.'/', $id) !== 1) {
            self::invalid('evidence_id');
        }

        return ['id' => $id, 'kind' => (string) strstr($id, ':', true), 'label' => $label, 'description' => $description, 'facts' => $facts];
    }

    /** A non-negative decimal with at most 4 places, re-formatted ("0.8125"). */
    public static function decimal(mixed $value, int $maximum = FixedPoint::ONE * 1000): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $units = is_string($value) ? FixedPoint::parse($value) : -1;
        } catch (InvalidArgumentException) {
            $units = -1;
        }
        if ($units < 0 || $units > $maximum) {
            self::invalid('decimal');
        }

        return FixedPoint::format($units);
    }

    /** A signed decimal (a delta), re-formatted ("-0.0600"). */
    public static function signedDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            self::invalid('decimal');
        }
        $negative = str_starts_with($value, '-');
        $formatted = self::decimal($negative ? substr($value, 1) : $value);

        return ($negative && $formatted !== '0.0000' ? '-' : '').$formatted;
    }

    public static function count(mixed $value, int $maximum = 1000000): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > $maximum) {
            self::invalid('count');
        }

        return $value;
    }

    /** An upper-case token such as IMPROVED or FUNCTION_DESIGN. */
    public static function token(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $value) !== 1) {
            self::invalid('token');
        }

        return $value;
    }

    public static function optionalToken(mixed $value): ?string
    {
        return $value === null ? null : self::token($value);
    }

    /** A lower-case key such as a roadmap step key ("fd-01") or a case id. */
    public static function key(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9_-]{0,47}$/', $value) !== 1) {
            self::invalid('key');
        }

        return $value;
    }

    /**
     * Server-owned catalog text (roadmap and challenge catalogs): bounded,
     * single-line, without control characters or the evidence delimiters.
     */
    public static function catalogText(mixed $value, int $maximum = 300): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]|<<<|>>>/', $value) === 1) {
            self::invalid('catalog_text');
        }

        return $value;
    }

    public static function version(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[0-9A-Za-z.+-]{1,64}$/', $value) !== 1) {
            self::invalid('version');
        }

        return $value;
    }

    public static function invalid(string $detail): never
    {
        throw new InsightException(InsightFailure::EvidenceInvalid, $detail);
    }
}
