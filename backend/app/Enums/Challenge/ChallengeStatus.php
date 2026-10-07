<?php

declare(strict_types=1);

namespace App\Enums\Challenge;

/**
 * Lifecycle of an assigned challenge (docs/architecture/coding-challenges-v1.md#lifecycle).
 *
 *     ASSIGNED ──submit──► EVALUATING ──pass──► PASSED
 *         ▲                    │ └──fail, attempts used up──► FAILED
 *         └──fail/error, attempts left──┘
 *
 * PASSED and FAILED are terminal and immutable. None of these states ever
 * changes a skill gap, competency or CodeDNA result.
 */
enum ChallengeStatus: string
{
    case Assigned = 'ASSIGNED';
    case Evaluating = 'EVALUATING';
    case Passed = 'PASSED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return $this === self::Passed || $this === self::Failed;
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Assigned => $to === self::Evaluating,
            self::Evaluating => in_array($to, [self::Assigned, self::Passed, self::Failed], true),
            self::Passed, self::Failed => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Assigned->value, self::Evaluating->value];
    }
}
