<?php

declare(strict_types=1);

namespace App\Services\Challenge;

use App\Enums\Challenge\ChallengeDifficulty;
use App\Enums\Competency\CompetencyKey;
use App\Services\Analyzer\CanonicalJson;

/**
 * One published challenge definition (challenge-definition/1) from the
 * server-owned catalog. Immutable: a material change is a new version.
 *
 * The definition holds trusted test assets. Only publicView() may leave the
 * server: it omits hidden cases entirely (inputs and expected outputs).
 */
final readonly class ChallengeDefinitionData
{
    public const VISIBLE = 'VISIBLE';

    public const HIDDEN = 'HIDDEN';

    /**
     * @param  array<string, mixed>  $document  the decoded definition file
     */
    public function __construct(public array $document) {}

    public function key(): string
    {
        return $this->document['key'];
    }

    public function version(): string
    {
        return $this->document['version'];
    }

    public function category(): CompetencyKey
    {
        return CompetencyKey::from($this->document['category']);
    }

    public function difficulty(): ChallengeDifficulty
    {
        return ChallengeDifficulty::from($this->document['difficulty']);
    }

    public function language(): string
    {
        return $this->document['language'];
    }

    public function entrypoint(): string
    {
        return $this->document['entrypoint'];
    }

    /**
     * @return array<string, int|bool>
     */
    public function rules(): array
    {
        return $this->document['rules'];
    }

    /**
     * @return list<array{id: string, visibility: string, args: list<mixed>, expected: mixed, description?: string}>
     */
    public function cases(): array
    {
        return $this->document['cases'];
    }

    /**
     * @return list<array{id: string, description: string, checks: list<string>}>
     */
    public function acceptanceCriteria(): array
    {
        return $this->document['acceptance_criteria'];
    }

    /**
     * SHA-256 of the canonical JSON of the whole definition.
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(self::decode($this->document));
    }

    /**
     * SHA-256 of everything that decides a verdict: entry point, cases
     * (inputs and expected outputs), rules and acceptance criteria.
     */
    public function testSuiteFingerprint(): string
    {
        return CanonicalJson::hash(self::decode($this->testSuite()));
    }

    /**
     * @return array<string, mixed>
     */
    public function testSuite(): array
    {
        return [
            'entrypoint' => $this->document['entrypoint'],
            'rules' => $this->document['rules'],
            'acceptance_criteria' => $this->document['acceptance_criteria'],
            'cases' => $this->document['cases'],
        ];
    }

    /**
     * What a client may see: everything except hidden cases. Visible cases
     * are examples (inputs and expected outputs); hidden cases are only
     * counted.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $document = $this->document;
        $visible = array_values(array_filter($document['cases'], fn (array $c): bool => $c['visibility'] === self::VISIBLE));

        return [
            'key' => $document['key'],
            'version' => $document['version'],
            'category' => $document['category'],
            'difficulty' => $document['difficulty'],
            'language' => $document['language'],
            'runtime' => $document['runtime'],
            'estimated_minutes' => $document['estimated_minutes'],
            'title' => $document['title'],
            'summary' => $document['summary'],
            'instructions' => $document['instructions'],
            'constraints' => $document['constraints'],
            'entrypoint' => $document['entrypoint'],
            'starter_code' => $document['starter_code'],
            'acceptance_criteria' => $document['acceptance_criteria'],
            'rules' => $document['rules'],
            'examples' => array_map(fn (array $c): array => [
                'id' => $c['id'],
                'description' => $c['description'] ?? null,
                'args' => $c['args'],
                'expected' => $c['expected'],
            ], $visible),
            'hidden_case_count' => count($document['cases']) - count($visible),
        ];
    }

    private static function decode(mixed $value): mixed
    {
        return json_decode((string) json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }
}
