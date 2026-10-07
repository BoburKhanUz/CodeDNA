<?php

declare(strict_types=1);

namespace App\Actions\Growth;

use App\Models\GrowthSnapshot;

final readonly class CalculatedGrowthSnapshot
{
    public function __construct(
        public GrowthSnapshot $snapshot,
        public bool $created,
    ) {}
}
