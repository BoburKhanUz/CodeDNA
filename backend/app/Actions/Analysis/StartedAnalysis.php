<?php

declare(strict_types=1);

namespace App\Actions\Analysis;

use App\Models\AnalysisRun;

/**
 * The run a start request resolved to: newly created, or the existing
 * equivalent run (same snapshot and result type) that was returned instead.
 */
final readonly class StartedAnalysis
{
    public function __construct(
        public AnalysisRun $run,
        public bool $created,
    ) {}
}
