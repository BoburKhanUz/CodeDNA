<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use RuntimeException;

/**
 * A skill gap snapshot that cannot give a roadmap.
 */
final class RoadmapGenerationException extends RuntimeException
{
    public const NO_ACTIONABLE_GAPS = 'NO_ACTIONABLE_GAPS';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
