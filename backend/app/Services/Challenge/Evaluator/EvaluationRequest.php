<?php

declare(strict_types=1);

namespace App\Services\Challenge\Evaluator;

use App\Services\Challenge\ChallengeDefinitionData;

/**
 * What the evaluator receives: the submission's source, the entry point and
 * the test inputs. Never expected outputs, visibility or rules.
 */
final readonly class EvaluationRequest
{
    public const PROTOCOL = 'codedna-evaluator/1';

    /**
     * @param  list<array{id: string, args: list<mixed>}>  $cases
     */
    public function __construct(
        public string $submissionId,
        public string $language,
        public string $entrypoint,
        public string $source,
        public array $cases,
    ) {}

    public static function for(string $submissionId, ChallengeDefinitionData $definition, string $source): self
    {
        return new self(
            $submissionId,
            $definition->language(),
            $definition->entrypoint(),
            $source,
            array_map(fn (array $case): array => ['id' => $case['id'], 'args' => $case['args']], $definition->cases()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'id' => $this->submissionId,
            'language' => $this->language,
            'entrypoint' => $this->entrypoint,
            'source' => $this->source,
            'cases' => $this->cases,
        ];
    }
}
