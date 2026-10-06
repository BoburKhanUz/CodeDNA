<?php

declare(strict_types=1);

namespace App\Services\Dna\Specification;

use App\Enums\Dna\DnaDimension;

/**
 * One dimension: its weight in the overall score and its components.
 */
final readonly class DimensionSpec
{
    /**
     * @param  int  $weight  share of the overall score, in fixed-point units
     * @param  list<ComponentSpec>  $components
     */
    public function __construct(
        public DnaDimension $dimension,
        public string $description,
        public int $weight,
        public array $components,
    ) {}
}
