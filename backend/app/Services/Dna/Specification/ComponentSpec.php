<?php

declare(strict_types=1);

namespace App\Services\Dna\Specification;

/**
 * One measured component of a dimension: a ratio of verified analyzer
 * counts, normalized "lower is better" between two thresholds.
 *
 *     value = Σ numerator / Σ denominator
 *     score = 1                              if value <= best
 *           = 0                              if value >= worst
 *           = (worst − value) / (worst − best) otherwise
 *
 * Inputs are metric paths into the verified result, e.g.
 * "metrics.overall.functions_total" or
 * "findings.by_rule.structure/function-length". Weights and thresholds are
 * fixed-point units (App\Services\Dna\FixedPoint).
 */
final readonly class ComponentSpec
{
    /**
     * @param  string  $key  stable identifier inside the dimension
     * @param  int  $weight  share of the dimension score, in units
     * @param  list<string>  $numerator  metric paths, summed
     * @param  list<string>  $denominator  metric paths, summed
     * @param  int  $best  value (in units) at or below which the score is 1
     * @param  int  $worst  value (in units) at or above which the score is 0
     * @param  int  $minimumDenominator  smallest denominator considered enough evidence
     * @param  bool  $share  the value is a part of a whole: numerator > denominator is an inconsistent result
     * @param  bool  $required  without it the dimension is unavailable; an optional component is skipped and its weight redistributed
     */
    public function __construct(
        public string $key,
        public string $description,
        public int $weight,
        public array $numerator,
        public array $denominator,
        public int $best,
        public int $worst,
        public int $minimumDenominator,
        public bool $share,
        public bool $required,
    ) {}
}
