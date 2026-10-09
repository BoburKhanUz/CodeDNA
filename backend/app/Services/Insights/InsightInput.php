<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Enums\Insights\InsightKind;
use App\Services\Analyzer\CanonicalJson;

/**
 * The canonical input of one insight (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#evidence):
 *
 * - payload: the only thing a model receives: schema and insight versions,
 *   the kind, fixed notes and the evidence catalog (sorted by id);
 * - lineage: the subject and its project, owner and snapshots; stored and
 *   fingerprinted, never sent;
 * - fingerprint: SHA-256 of the canonical JSON of {lineage, payload}. Any
 *   change in the evidence (a newer analysis, a completed roadmap step) is a
 *   different fingerprint, so a different insight.
 */
final readonly class InsightInput
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $lineage
     */
    public function __construct(public InsightKind $kind, public array $payload, public array $lineage) {}

    /**
     * @return list<array{id: string, kind: string, label: string, description: string, facts: array<string, mixed>}>
     */
    public function evidence(): array
    {
        return $this->payload['evidence'];
    }

    /** @return list<string> */
    public function evidenceIds(): array
    {
        return array_column($this->evidence(), 'id');
    }

    /**
     * @return array{id: string, kind: string, label: string, description: string, facts: array<string, mixed>}|null
     */
    public function item(string $id): ?array
    {
        foreach ($this->evidence() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode(self::decode($this->payload));
    }

    public function fingerprint(): string
    {
        return CanonicalJson::hash(self::decode(['lineage' => $this->lineage, 'payload' => $this->payload]));
    }

    /**
     * @return array{lineage: array<string, string|null>, payload: array<string, mixed>}
     */
    public function toStored(): array
    {
        return ['lineage' => $this->lineage, 'payload' => $this->payload];
    }

    /**
     * Every number that appears in a fact, normalized ("0.0600" and "0.06"
     * are the same; the sign is dropped because text never carries it), so
     * that a claim may only repeat numbers that exist in the evidence.
     *
     * @return array<string, true>
     */
    public function numbers(): array
    {
        $numbers = [];
        foreach ($this->evidence() as $item) {
            array_walk_recursive($item['facts'], function (mixed $value) use (&$numbers): void {
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1)) {
                    $numbers[self::normalizeNumber(ltrim((string) $value, '-'))] = true;
                }
            });
        }

        return $numbers;
    }

    public static function normalizeNumber(string $number): string
    {
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }
        $number = ltrim($number, '0');

        return $number === '' || $number[0] === '.' ? '0'.$number : $number;
    }

    private static function decode(mixed $value): mixed
    {
        return json_decode((string) json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
