<?php

declare(strict_types=1);

namespace App\Services\Competency;

/**
 * One piece of evidence of a competency: a component of a DNA dimension,
 * identified as "DIMENSION.component" (e.g. "COMPLEXITY.mean_cyclomatic_complexity").
 * The component's normalized score (computed by the scoring engine from raw
 * counts) is used as is.
 */
final readonly class EvidenceRule
{
    /**
     * @param  int  $weight  share of the competency score, in fixed-point units
     * @param  bool  $required  without it the competency is not assessed; an optional rule is skipped and its weight redistributed
     */
    public function __construct(
        public string $dimension,
        public string $component,
        public int $weight,
        public bool $required,
        public string $rationale,
    ) {}

    public function source(): string
    {
        return "{$this->dimension}.{$this->component}";
    }
}
