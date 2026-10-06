<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How source code reaches CodeDNA: a project's configured origin, and how a
 * particular snapshot was obtained.
 */
enum SourceType: string
{
    case Upload = 'UPLOAD';
    case Repository = 'REPOSITORY';
}
