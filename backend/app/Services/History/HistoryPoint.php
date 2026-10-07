<?php

declare(strict_types=1);

namespace App\Services\History;

use App\Models\AnalysisRun;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\GrowthSnapshot;
use App\Models\SkillGapSnapshot;
use App\Models\SourceSnapshot;
use App\Services\Growth\GrowthAssessment;
use Illuminate\Support\Carbon;

/**
 * One point of a project's historical DNA (docs/architecture/historical-dna-v1.md):
 * a DNA snapshot of a successful analysis run, with the stored layers
 * derived from it. A read model over immutable records; it holds nothing
 * of its own.
 *
 * - competency and skill gaps are null when that layer was never
 *   calculated for the DNA snapshot (never fabricated);
 * - growth is the Phase 18 growth snapshot of the point's assessment under
 *   the current growth rules, when there is one.
 */
final readonly class HistoryPoint
{
    public function __construct(
        public DnaSnapshot $dna,
        public AnalysisRun $run,
        public SourceSnapshot $source,
        public ?CompetencySnapshot $competency,
        public ?SkillGapSnapshot $skillGaps,
        public ?GrowthSnapshot $growth,
    ) {}

    /** When the code was assessed: the analysis run's completion. */
    public function analyzedAt(): Carbon
    {
        /** @var Carbon $completed */
        $completed = $this->run->completed_at;

        return $completed;
    }

    /** The stored values and versions of every available layer, as Phase 18 reads them. */
    public function assessment(): GrowthAssessment
    {
        return GrowthAssessment::forLayers($this->dna, $this->competency, $this->skillGaps, $this->skillGaps->results ?? []);
    }

    /**
     * The metric types this point has stored values for.
     *
     * @return list<string>
     */
    public function layers(): array
    {
        return [
            'DNA',
            ...($this->competency !== null ? ['COMPETENCY'] : []),
            ...($this->competency !== null && $this->skillGaps !== null ? ['SKILL_GAP'] : []),
        ];
    }
}
