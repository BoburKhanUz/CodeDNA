<?php

declare(strict_types=1);

namespace App\Enums\Challenge;

/**
 * Lifecycle of one submission. PASSED and FAILED are verdicts of the
 * deterministic evaluation; ERROR means the evaluation itself could not be
 * completed (it consumes no attempt). Terminal states are immutable.
 */
enum SubmissionStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Passed = 'PASSED';
    case Failed = 'FAILED';
    case Error = 'ERROR';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Passed, self::Failed, self::Error], true);
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Queued => in_array($to, [self::Running, self::Error], true),
            self::Running => in_array($to, [self::Running, self::Passed, self::Failed, self::Error], true),
            default => false,
        };
    }
}
