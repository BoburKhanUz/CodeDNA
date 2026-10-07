<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

/**
 * What the developer should prioritise next, derived only from a skill gap
 * snapshot (docs/architecture/learning-roadmap-v1.md#development-focus).
 */
final readonly class DevelopmentFocus
{
    /**
     * @param  list<array<string, mixed>>  $selected  focus entries in rank order (the roadmap's tracks)
     * @param  list<array<string, mixed>>  $excluded  competencies that are not part of the roadmap, with a reason
     */
    public function __construct(
        public array $selected,
        public array $excluded,
    ) {}

    /**
     * @return array{selected: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['selected' => $this->selected, 'excluded' => $this->excluded];
    }
}
