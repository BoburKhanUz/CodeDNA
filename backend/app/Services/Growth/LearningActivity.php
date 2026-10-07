<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Enums\Challenge\SubmissionStatus;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Learning activity of a project between two assessment times, as context
 * only (growth-tracking-v1.md#activity-context): roadmap steps completed and
 * challenges passed in (from, to]. Counted when read, never stored, and
 * never growth or history evidence. One query.
 */
final class LearningActivity
{
    /**
     * @return array{roadmap_steps_completed: int, challenges_passed: int}
     */
    public static function between(string $projectId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $window = [$from, $to];
        $row = DB::selectOne(
            'SELECT
                (SELECT count(*) FROM roadmap_step_completions WHERE project_id = ? AND completed_at > ? AND completed_at <= ?) AS steps,
                (SELECT count(*) FROM challenge_submissions WHERE project_id = ? AND status = ? AND completed_at > ? AND completed_at <= ?) AS challenges',
            [$projectId, ...$window, $projectId, SubmissionStatus::Passed->value, ...$window],
        );

        return ['roadmap_steps_completed' => (int) $row->steps, 'challenges_passed' => (int) $row->challenges];
    }
}
