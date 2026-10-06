<?php

declare(strict_types=1);

namespace App\Actions\Dna;

use App\Models\DnaSnapshot;

/**
 * The DNA snapshot of a run for the scoring version, and whether this call
 * created it (false: it already existed).
 */
final readonly class CalculatedDnaSnapshot
{
    public function __construct(
        public DnaSnapshot $snapshot,
        public bool $created,
    ) {}
}
