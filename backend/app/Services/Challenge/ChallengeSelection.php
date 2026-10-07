<?php

declare(strict_types=1);

namespace App\Services\Challenge;

/**
 * A selected challenge, the gap it addresses and why it was chosen. The
 * provenance is stored with the challenge, so its meaning never depends on
 * later data.
 */
final readonly class ChallengeSelection
{
    /**
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public ChallengeDefinitionData $definition,
        public string $competencyKey,
        public array $provenance,
    ) {}
}
