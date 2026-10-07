<?php

declare(strict_types=1);

namespace App\Enums\GitHub;

/**
 * Lifecycle of one GitHub import (docs/architecture/github-integration-v1.md#import-lifecycle):
 * QUEUED → RUNNING → SUCCEEDED | FAILED, or CANCELLED when the connection is
 * disconnected first. Terminal imports never change.
 */
enum GitHubImportStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Queued->value, self::Running->value];
    }
}
