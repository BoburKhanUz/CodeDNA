<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Models\GitHubImport;

/**
 * Result of RequestGitHubImport: the import, and whether this request queued it.
 */
final readonly class RequestedGitHubImport
{
    public function __construct(public GitHubImport $import, public bool $created) {}
}
