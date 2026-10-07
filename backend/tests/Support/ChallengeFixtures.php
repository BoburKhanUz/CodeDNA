<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Project;
use App\Models\SkillGapSnapshot;
use stdClass;

/**
 * Skill gap snapshots for challenge tests, created by the real engines.
 *
 * - rateable: one material gap, CODE_HYGIENE (HIGH);
 * - manyGaps: material gaps in all four competencies.
 */
final class ChallengeFixtures
{
    public static function oneGap(Project $project): SkillGapSnapshot
    {
        return AssessmentFixtures::skillGaps($project);
    }

    public static function manyGaps(Project $project): SkillGapSnapshot
    {
        return AssessmentFixtures::skillGaps($project, self::everythingBelowTarget(...));
    }

    /** Every competency measurable and below its target. */
    public static function everythingBelowTarget(stdClass $result): void
    {
        StoredResults::set($result, [
            'files_analyzable' => 20, 'files_parsed' => 18, 'files_parse_error' => 2,
            'functions_total' => 40, 'complexity_total' => 280, 'complexity_over_threshold' => 8, 'types' => 10,
        ], [
            'structure/function-length' => 4, 'structure/parameter-count' => 4, 'structure/nesting-depth' => 4,
            'structure/class-length' => 2, 'parse/syntax-error' => 2,
        ]);
    }
}
