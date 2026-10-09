<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Services\GitHub\GitHubNames;

/**
 * Bitbucket Cloud adapter (Phase 28, docs/integrations/bitbucket-cloud.md):
 * REST API 2.0 and an OAuth 2.0 consumer with the read-only `account` and
 * `repository` scopes. Bitbucket Data Center / Server is not supported.
 *
 * Repositories are identified by their workspace and repository UUIDs (stable
 * across renames): the opaque ID is "{workspace-uuid}/{repository-uuid}".
 */
final readonly class BitbucketProvider implements RepositoryProvider
{
    private const API = '/2.0';

    private const UUID = '\{[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}';

    private const SLUG = '/^[A-Za-z0-9_.-]{1,128}$/D';

    public function __construct(private ProviderHttp $http, private ProviderSettings $settings) {}

    public function key(): RepositoryProviderKey
    {
        return RepositoryProviderKey::Bitbucket;
    }

    public function settings(): ProviderSettings
    {
        return $this->settings;
    }

    public function authorizationUrl(string $state): string
    {
        // Scopes and the callback URL are part of the OAuth consumer's settings on Bitbucket.
        return $this->settings->webUrl.'/site/oauth2/authorize?'.http_build_query([
            'client_id' => $this->settings->clientId,
            'response_type' => 'code',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(#[\SensitiveParameter] string $code): ProviderTokens
    {
        return ProviderTokens::fromApi($this->http->postForm('/site/oauth2/access_token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
        ], basicAuth: true));
    }

    public function refresh(#[\SensitiveParameter] string $refreshToken): ProviderTokens
    {
        return ProviderTokens::fromApi($this->http->postForm('/site/oauth2/access_token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], basicAuth: true));
    }

    public function revoke(#[\SensitiveParameter] string $accessToken): bool
    {
        // Bitbucket Cloud offers no OAuth token revocation endpoint: the user
        // revokes access to the consumer in their Bitbucket settings. CodeDNA
        // deletes its copy of the tokens either way.
        return false;
    }

    public function identity(#[\SensitiveParameter] string $token): ProviderIdentity
    {
        $user = $this->http->getJson(self::API.'/user', $token)['json'];
        $name = is_array($user) ? ($user['username'] ?? $user['nickname'] ?? null) : null;
        if (! is_array($user) || ! is_string($user['uuid'] ?? null) || preg_match('/^'.self::UUID.'$/D', $user['uuid']) !== 1
            || ! is_string($name) || $name === '' || mb_strlen($name) > 255 || preg_match('/\p{C}/u', $name) === 1) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return new ProviderIdentity($user['uuid'], $name);
    }

    public function isRepositoryId(string $id): bool
    {
        return preg_match('#^'.self::UUID.'/'.self::UUID.'$#D', $id) === 1;
    }

    public function repositories(#[\SensitiveParameter] string $token, int $page, int $perPage): array
    {
        // Repositories where the user has an explicit role: never a public listing.
        $data = $this->http->getJson(self::API.'/repositories', $token, [
            'role' => 'member',
            'sort' => '-updated_on',
            'pagelen' => $perPage,
            'page' => $page,
        ])['json'];
        if (! is_array($data) || ! is_array($data['values'] ?? null) || ! array_is_list($data['values'])) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return [
            'repositories' => array_map(self::repositoryFrom(...), $data['values']),
            'has_more' => is_string($data['next'] ?? null),
        ];
    }

    public function repository(#[\SensitiveParameter] string $token, string $id): ProviderRepository
    {
        if (! $this->isRepositoryId($id)) {
            throw new ProviderException(ProviderError::NotFound);
        }
        [$workspace, $repository] = explode('/', $id);
        $found = self::repositoryFrom($this->http->getJson(self::API.'/repositories/'.rawurlencode($workspace).'/'.rawurlencode($repository), $token)['json']);
        if ($found->id !== $id) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return $found;
    }

    public function branches(#[\SensitiveParameter] string $token, ProviderRepository $repository, int $page, int $perPage): array
    {
        $data = $this->http->getJson($this->repositoryPath($repository).'/refs/branches', $token, ['pagelen' => $perPage, 'page' => $page])['json'];
        if (! is_array($data) || ! is_array($data['values'] ?? null) || ! array_is_list($data['values'])) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        $names = [];
        foreach ($data['values'] as $branch) {
            if (is_array($branch) && GitHubNames::isBranch($branch['name'] ?? null)) {
                $names[] = (string) $branch['name'];
            }
        }

        return ['branches' => $names, 'has_more' => is_string($data['next'] ?? null)];
    }

    public function branchHead(#[\SensitiveParameter] string $token, ProviderRepository $repository, string $branch): string
    {
        if (! GitHubNames::isBranch($branch)) {
            throw new ProviderException(ProviderError::NotFound);
        }
        $data = $this->http->getJson($this->repositoryPath($repository).'/refs/branches/'.GitHubNames::branchPath($branch), $token)['json'];
        $sha = is_array($data) && is_array($data['target'] ?? null) ? ($data['target']['hash'] ?? null) : null;
        if (! is_array($data) || ($data['name'] ?? null) !== $branch || ! GitHubNames::isCommitSha($sha)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        return (string) $sha;
    }

    public function downloadArchive(#[\SensitiveParameter] string $token, ProviderRepository $repository, string $sha, string $destination, int $maxBytes): void
    {
        if (! GitHubNames::isCommitSha($sha) || ! isset($repository->path['workspace'], $repository->path['slug'])) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        // Bitbucket Cloud's archive download for one commit (the "get" endpoint on the web origin).
        $this->http->download(
            $this->settings->webUrl.'/'.rawurlencode($repository->path['workspace']).'/'.rawurlencode($repository->path['slug']).'/get/'.$sha.'.zip',
            $token, $destination, $maxBytes,
        );
    }

    private function repositoryPath(ProviderRepository $repository): string
    {
        [$workspace, $uuid] = explode('/', $repository->id);

        return self::API.'/repositories/'.rawurlencode($workspace).'/'.rawurlencode($uuid);
    }

    /**
     * @throws ProviderException
     */
    private static function repositoryFrom(mixed $data): ProviderRepository
    {
        $workspace = is_array($data) && is_array($data['workspace'] ?? null) ? $data['workspace'] : [];
        if (! is_array($data) || ! is_string($data['uuid'] ?? null) || preg_match('/^'.self::UUID.'$/D', $data['uuid']) !== 1
            || ! is_string($workspace['uuid'] ?? null) || preg_match('/^'.self::UUID.'$/D', $workspace['uuid']) !== 1
            || ! is_string($workspace['slug'] ?? null) || preg_match(self::SLUG, $workspace['slug']) !== 1
            || ! is_string($data['slug'] ?? null) || preg_match(self::SLUG, $data['slug']) !== 1
            || ($data['full_name'] ?? null) !== $workspace['slug'].'/'.$data['slug']
            || ! is_bool($data['is_private'] ?? null)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }
        $main = is_array($data['mainbranch'] ?? null) ? ($data['mainbranch']['name'] ?? null) : null;

        return new ProviderRepository(
            id: $workspace['uuid'].'/'.$data['uuid'],
            fullName: $data['full_name'],
            private: $data['is_private'],
            // Bitbucket Cloud has no archived state.
            archived: false,
            defaultBranch: GitHubNames::isBranch($main) ? (string) $main : null,
            path: ['workspace' => $workspace['slug'], 'slug' => $data['slug']],
        );
    }
}
