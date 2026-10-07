<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

/**
 * A generated roadmap before it is stored: the focus, the selected tracks
 * and their ordered steps, and the fingerprint of all of it.
 */
final readonly class RoadmapPlan
{
    /**
     * @param  list<array<string, mixed>>  $tracks
     * @param  list<array<string, mixed>>  $steps  in learning order (position 1..n)
     * @param  array<string, mixed>  $content  the canonical, fingerprinted content
     */
    public function __construct(
        public DevelopmentFocus $focus,
        public array $tracks,
        public array $steps,
        public array $content,
        public string $fingerprint,
    ) {}
}
