<?php

declare(strict_types=1);

namespace App\Services\Repositories;

/**
 * A repository as a provider reports it, normalized (Phase 28). Every field
 * is validated by the adapter that builds it; nothing comes from a client.
 */
final readonly class ProviderRepository
{
    public function __construct(
        /** Opaque, stable identifier the client sends back (GitLab: "123"; Bitbucket: "{workspace-uuid}/{repository-uuid}"). */
        public string $id,
        /** "namespace/name" or "workspace/slug", for display and provenance. */
        public string $fullName,
        public bool $private,
        public bool $archived,
        /** Null for an empty repository. */
        public ?string $defaultBranch,
        /** Provider path segments the adapter needs for later calls (never shown). */
        public array $path = [],
    ) {}

    /** @return array{id: string, full_name: string, private: bool, archived: bool, default_branch: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'full_name' => $this->fullName, 'private' => $this->private, 'archived' => $this->archived, 'default_branch' => $this->defaultBranch];
    }
}
