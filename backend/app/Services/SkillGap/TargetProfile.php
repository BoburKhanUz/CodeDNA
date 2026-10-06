<?php

declare(strict_types=1);

namespace App\Services\SkillGap;

/**
 * A named, versioned set of target competency scores. A measurable
 * engineering standard, never a job title or seniority level.
 */
final readonly class TargetProfile
{
    /**
     * @param  array<string, int>  $targets  competency key => target score (fixed-point units), in display order
     * @param  array<string, string>  $rationales  competency key => why this target
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $description,
        public array $targets,
        public array $rationales,
    ) {}
}
