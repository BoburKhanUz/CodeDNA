<?php

declare(strict_types=1);

namespace App\Services\GitHub;

/**
 * The repository metadata CodeDNA keeps, read from a GitHub API response
 * and checked field by field. Nothing else from the response is kept.
 */
final readonly class GitHubRepository
{
    public function __construct(
        public int $id,
        public string $owner,
        public string $name,
        public string $fullName,
        public bool $private,
        public bool $archived,
        public bool $disabled,
        public string $defaultBranch,
    ) {}

    /**
     * @throws GitHubException
     */
    public static function fromApi(mixed $data): self
    {
        if (! is_array($data) || ! is_int($data['id'] ?? null) || $data['id'] <= 0 || ! is_array($data['owner'] ?? null)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }
        $owner = $data['owner']['login'] ?? null;
        $name = $data['name'] ?? null;
        $default = $data['default_branch'] ?? null;
        if (! GitHubNames::isLogin($owner) || ! GitHubNames::isRepositoryName($name) || ! is_string($default) || ! GitHubNames::isBranch($default)
            || ($data['full_name'] ?? null) !== $owner.'/'.$name || ! is_bool($data['private'] ?? null) || ! is_bool($data['archived'] ?? null)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return new self(
            id: $data['id'],
            owner: $owner,
            name: $name,
            fullName: $owner.'/'.$name,
            private: $data['private'],
            archived: $data['archived'],
            disabled: ($data['disabled'] ?? false) === true,
            defaultBranch: $default,
        );
    }

    public function path(): string
    {
        return GitHubNames::repositoryPath($this->owner, $this->name);
    }

    /**
     * @return array{id: int, owner: string, name: string, full_name: string, private: bool, archived: bool, default_branch: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'name' => $this->name,
            'full_name' => $this->fullName,
            'private' => $this->private,
            'archived' => $this->archived,
            'default_branch' => $this->defaultBranch,
        ];
    }
}
