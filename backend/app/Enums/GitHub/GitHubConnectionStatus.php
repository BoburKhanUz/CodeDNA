<?php

declare(strict_types=1);

namespace App\Enums\GitHub;

/**
 * A project's GitHub connection: ACTIVE until the owner disconnects it, once.
 * A disconnected connection is kept as provenance of its imports.
 */
enum GitHubConnectionStatus: string
{
    case Active = 'ACTIVE';
    case Disconnected = 'DISCONNECTED';
}
