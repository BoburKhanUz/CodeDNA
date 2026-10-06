<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one analysis run:
 *
 *     QUEUED ──► RUNNING ──┬──► SUCCEEDED
 *        │                 ├──► FAILED
 *        │                 └──► CANCELLED
 *        └──► FAILED | CANCELLED   (dispatch failure, stale run, user cancel)
 *
 * Terminal states never change again (historical record).
 */
enum AnalysisRunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => in_array($next, [self::Running, self::Failed, self::Cancelled], true),
            self::Running => in_array($next, [self::Succeeded, self::Failed, self::Cancelled], true),
            self::Succeeded, self::Failed, self::Cancelled => false,
        };
    }
}
