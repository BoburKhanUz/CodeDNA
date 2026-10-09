<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Services\GitHub\GitHubNames;

/**
 * GitLab adapter (Phase 28, docs/integrations/gitlab.md): GitLab.com or one
 * administrator-configured self-managed instance (GITLAB_BASE_URL), REST API
 * v4 and OAuth 2.0 with the read-only `read_api` scope.
 */
final readonly class GitLabProvider implements RepositoryProvider
{
    private const API = '/api/v4';

    public function __construct(private ProviderHttp $http, private ProviderSettings $settings) {}

    public function key(): RepositoryProviderKey
    {
        return RepositoryProviderKey::GitLab;
    }

    public function settings(): ProviderSettings
    {
        return $this->settings;
    }

    public function authorizationUrl(string $state): string
    {
        return $this->settings->webUrl.'/oauth/authorize?'.http_build_query([
            'client_id' => $this->settings->clientId,
            'redirect_uri' => $this->settings->callbackUrl,
            'response_type' => 'code',
            'state' => $state,
            'scope' => $this->settings->scopes,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(#[\SensitiveParameter] string $code): ProviderTokens
    {
        return ProviderTokens::fromApi($this->http->postForm('/oauth/token', [
            'client_id' => $this->settings->clientId,
            'client_secret' => $this->settings->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->settings->callbackUrl,
        ]));
    }

    public function refresh(#[\SensitiveParameter] string $refreshToken): ProviderTokens
    {
        return ProviderTokens::fromApi($this->http->postForm('/oauth/token', [
            'client_id' => $this->settings->clientId,
            'client_secret' => $this->settings->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $this->settings->callbackUrl,
        ]));
    }

    public function revoke(#[\SensitiveParameter] string $accessToken): bool
    {
        $this->http->postForm('/oauth/revoke', [
            'client_id' => $this->settings->clientId,
            'client_secret' => $this->settings->clientSecret,
            'token' => $accessToken,
        ], expectJson: false);

        return true;
    }

    public function identity(#[\SensitiveParameter] string $token): ProviderIdentity
    {
        $user = $this->http->getJson(self::API.'/user', $token)['json'];
        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] < 1
            || ! is_string($user['username'] ?? null) || preg_match('/^[A-Za-z0-9_.-]{1,255}$/', $user['username']) !== 1) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return new ProviderIdentity((string) $user['id'], $user['username']);
    }

    public function isRepositoryId(string $id): bool
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1;
    }

    public function repositories(#[\SensitiveParameter] string $token, int $page, int $perPage): array
    {
        // Projects the user is a member of with at least Reporter access:
        // the level that may read code. Never a global search.
        $response = $this->http->getJson(self::API.'/projects', $token, [
            'membership' => 'true',
            'min_access_level' => 20,
            'order_by' => 'last_activity_at',
            'sort' => 'desc',
            'per_page' => $perPage,
            'page' => $page,
        ]);
        if (! is_array($response['json']) || ! array_is_list($response['json'])) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return [
            'repositories' => array_map(self::project(...), $response['json']),
            'has_more' => ($response['headers']['x-next-page'] ?? '') !== '',
        ];
    }

    public function repository(#[\SensitiveParameter] string $token, string $id): ProviderRepository
    {
        if (! $this->isRepositoryId($id)) {
            throw new ProviderException(ProviderError::NotFound);
        }
        $repository = self::project($this->http->getJson(self::API.'/projects/'.$id, $token)['json']);
        if ($repository->id !== $id) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return $repository;
    }

    public function branches(#[\SensitiveParameter] string $token, ProviderRepository $repository, int $page, int $perPage): array
    {
        $response = $this->http->getJson(self::API.'/projects/'.$repository->id.'/repository/branches', $token, ['per_page' => $perPage, 'page' => $page]);
        if (! is_array($response['json']) || ! array_is_list($response['json'])) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        $names = [];
        foreach ($response['json'] as $branch) {
            // Names CodeDNA cannot use safely are left out, never altered.
            if (is_array($branch) && GitHubNames::isBranch($branch['name'] ?? null)) {
                $names[] = (string) $branch['name'];
            }
        }

        return ['branches' => $names, 'has_more' => ($response['headers']['x-next-page'] ?? '') !== ''];
    }

    public function branchHead(#[\SensitiveParameter] string $token, ProviderRepository $repository, string $branch): string
    {
        if (! GitHubNames::isBranch($branch)) {
            throw new ProviderException(ProviderError::NotFound);
        }
        // GitLab takes the whole branch name as one encoded path segment.
        $data = $this->http->getJson(self::API.'/projects/'.$repository->id.'/repository/branches/'.rawurlencode($branch), $token)['json'];
        $sha = is_array($data) && is_array($data['commit'] ?? null) ? ($data['commit']['id'] ?? null) : null;
        if (! is_array($data) || ($data['name'] ?? null) !== $branch || ! GitHubNames::isCommitSha($sha)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return (string) $sha;
    }

    public function downloadArchive(#[\SensitiveParameter] string $token, ProviderRepository $repository, string $sha, string $destination, int $maxBytes): void
    {
        if (! GitHubNames::isCommitSha($sha)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        $this->http->download(
            $this->settings->apiUrl.self::API.'/projects/'.$repository->id.'/repository/archive.zip?sha='.$sha,
            $token, $destination, $maxBytes,
        );
    }

    /**
     * @throws ProviderException
     */
    private static function project(mixed $data): ProviderRepository
    {
        if (! is_array($data) || ! is_int($data['id'] ?? null) || $data['id'] < 1
            || ! is_string($data['path_with_namespace'] ?? null) || preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)+$#D', $data['path_with_namespace']) !== 1
            || strlen($data['path_with_namespace']) > 255
            || array_intersect(explode('/', $data['path_with_namespace']), ['.', '..']) !== []
            || ! in_array($data['visibility'] ?? null, ['private', 'internal', 'public'], true)
            || ! is_bool($data['archived'] ?? null)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        $default = $data['default_branch'] ?? null;

        return new ProviderRepository(
            id: (string) $data['id'],
            fullName: $data['path_with_namespace'],
            // "internal" is visible to every signed-in user of the instance: not public.
            private: $data['visibility'] !== 'public',
            archived: $data['archived'],
            defaultBranch: GitHubNames::isBranch($default) ? (string) $default : null,
        );
    }
}
