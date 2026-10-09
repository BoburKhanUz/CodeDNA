<?php

declare(strict_types=1);

namespace App\Enums\Insights;

/**
 * What an AI insight interprets (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#insights). Each kind has exactly
 * one kind of subject; DNA and skill-gap interpretation remain the Phase 15
 * assessment.
 */
enum InsightKind: string
{
    /** A COMPARED growth snapshot: what was measured to change, and what was not. */
    case GrowthInterpretation = 'GROWTH_INTERPRETATION';

    /** An active learning roadmap: why its tracks, and which available step to take next. */
    case RoadmapGuidance = 'ROADMAP_GUIDANCE';

    /** An evaluated challenge submission: what the deterministic evaluator found. */
    case ChallengeFeedback = 'CHALLENGE_FEEDBACK';

    public function subjectColumn(): string
    {
        return match ($this) {
            self::GrowthInterpretation => 'growth_snapshot_id',
            self::RoadmapGuidance => 'roadmap_snapshot_id',
            self::ChallengeFeedback => 'challenge_submission_id',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
