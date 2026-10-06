<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Lifecycle of an AI assessment (docs/architecture/ai-assessment-v1.md#lifecycle):
 * QUEUED → RUNNING → SUCCEEDED | FAILED, or QUEUED → FAILED (stale).
 * SUCCEEDED and FAILED are terminal: the record never changes again; a new
 * request creates a new assessment.
 */
enum AssessmentStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Queued => in_array($to, [self::Running, self::Failed], true),
            self::Running => in_array($to, [self::Running, self::Succeeded, self::Failed], true),
            self::Succeeded, self::Failed => false,
        };
    }
}
