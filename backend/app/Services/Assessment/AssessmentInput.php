<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Services\Analyzer\CanonicalJson;

/**
 * The deterministic, bounded input of one assessment
 * (docs/architecture/ai-assessment-v1.md#input).
 *
 * - $payload is the only thing sent to a provider: versions, statuses and
 *   the evidence catalog built from persisted snapshots. It contains no
 *   source code, file names, project names, findings text, user data or
 *   storage references.
 * - $lineage ties the input to the snapshots it was built from (identifiers
 *   and specification fingerprints). It is stored and fingerprinted, never
 *   sent.
 *
 * The fingerprint is the SHA-256 of the canonical JSON of both.
 */
final readonly class AssessmentInput
{
    /**
     * @param  array<string, mixed>  $lineage
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $lineage,
        public array $payload,
    ) {}

    /**
     * @param  array<array-key, mixed>  $stored  the ai_assessments.input column
     */
    public static function fromStored(array $stored): self
    {
        return new self(
            is_array($stored['lineage'] ?? null) ? $stored['lineage'] : [],
            is_array($stored['payload'] ?? null) ? $stored['payload'] : [],
        );
    }

    /**
     * @return array{lineage: array<string, mixed>, payload: array<string, mixed>}
     */
    public function toStored(): array
    {
        return ['lineage' => $this->lineage, 'payload' => $this->payload];
    }

    /**
     * The payload as canonical JSON: what the provider receives.
     */
    public function canonicalJson(): string
    {
        return CanonicalJson::encode(self::decode($this->payload));
    }

    public function fingerprint(): string
    {
        return CanonicalJson::hash(self::decode($this->toStored()));
    }

    /**
     * @return list<string>
     */
    public function evidenceIds(): array
    {
        return array_values(array_map(fn (array $item): string => (string) $item['id'], $this->evidence()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function evidence(): array
    {
        $evidence = $this->payload['evidence'] ?? [];

        return is_array($evidence) ? array_values(array_filter($evidence, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function evidenceItem(string $id): ?array
    {
        foreach ($this->evidence() as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Every number that appears in an evidence fact, normalized ("0.9000"
     * and "0.9" are the same number). A response may only mention these.
     *
     * @return array<string, true>
     */
    public function numbers(): array
    {
        $numbers = [];
        foreach ($this->evidence() as $item) {
            array_walk_recursive($item, function (mixed $value) use (&$numbers): void {
                if (is_int($value) || (is_string($value) && preg_match('/^\d+(?:\.\d+)?$/', $value) === 1)) {
                    $numbers[self::normalizeNumber((string) $value)] = true;
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
