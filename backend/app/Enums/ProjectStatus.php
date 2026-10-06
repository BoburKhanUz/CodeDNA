<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stored as VARCHAR with a CHECK constraint (docs/architecture/data-model.md#states).
 */
enum ProjectStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
