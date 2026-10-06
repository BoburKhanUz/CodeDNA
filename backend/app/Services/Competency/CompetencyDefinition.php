<?php

declare(strict_types=1);

namespace App\Services\Competency;

use App\Enums\Competency\CompetencyKey;

/**
 * One competency: what it describes, the evidence it is built from, and the
 * languages for which that evidence is only partially measured.
 */
final readonly class CompetencyDefinition
{
    /**
     * @param  list<EvidenceRule>  $evidence
     * @param  array<string, string>  $partialLanguages  language => documented limitation of the evidence for it
     */
    public function __construct(
        public CompetencyKey $key,
        public string $description,
        public array $evidence,
        public array $partialLanguages,
    ) {}
}
