<?php

declare(strict_types=1);

namespace App\Services\Repositories;

/** The provider account that authorized CodeDNA (Phase 28), as the provider reports it. */
final readonly class ProviderIdentity
{
    public function __construct(
        /** Stable provider user ID (GitLab numeric ID, Bitbucket account UUID). */
        public string $id,
        public string $username,
    ) {}
}
