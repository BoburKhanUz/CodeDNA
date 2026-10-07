<?php

declare(strict_types=1);

namespace App\Enums\Roadmap;

/**
 * Lifecycle of a learning roadmap (docs/architecture/learning-roadmap-v1.md#statuses).
 *
 * - ACTIVE: the current roadmap of the project; steps can be completed;
 * - COMPLETED: every step was marked complete by the developer. Learning
 *   progress only: it never means a competency improved or a gap closed;
 * - SUPERSEDED: a roadmap for a newer skill gap analysis replaced it.
 *
 * COMPLETED and SUPERSEDED are final; nothing is deleted.
 */
enum RoadmapStatus: string
{
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Superseded = 'SUPERSEDED';

    public function isFinal(): bool
    {
        return $this !== self::Active;
    }
}
