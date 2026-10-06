<?php

declare(strict_types=1);

namespace App\Enums\Competency;

/**
 * ASSESSED: at least one competency was assessed. INSUFFICIENT_DATA: none
 * could be (the details still say why for each).
 */
enum CompetencySnapshotStatus: string
{
    case Assessed = 'ASSESSED';
    case InsufficientData = 'INSUFFICIENT_DATA';
}
