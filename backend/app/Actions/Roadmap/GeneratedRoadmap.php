<?php

declare(strict_types=1);

namespace App\Actions\Roadmap;

use App\Models\RoadmapSnapshot;

final readonly class GeneratedRoadmap
{
    public function __construct(
        public RoadmapSnapshot $roadmap,
        public bool $created,
    ) {}
}
