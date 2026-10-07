<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Typed GitHub operations (docs/architecture/github-integration-v1.md#github-client).
 * Paths are built only from validated identifiers; every response is
 * checked before use and reduced to the fields CodeDNA needs.
 *
 * Three credentials, each used only where needed:
 * - the App JWT (RS256, 9 minutes): finds a repository's installation and
 *   mints installation tokens;
 * - an installation token (one hour, minted per import, limited to one
 *   repository and to contents:read + metadata:read): downloads archives.
 *   It is never stored;
 * - the user's access token: lists what this user can reach through the
 *   App, so access is always checked as the user.
 */
final readonly class GitHubApi
{
    /** The only permissions an installation token is ever asked for. */
    public const INSTALLATION_PERMISSIONS = ['contents' => 'read', 'metadata' => 'read'];

    public const MAX_PER_PAGE = 100;

    public function __construct(private GitHubHttp $http, private GitHubSettings $settings) {}

    /**
     * @throws GitHubException
     */
    public function appJwt(): string
    {
        if (! $this->settings->configured()) {
            throw new GitHubException(GitHubError::NotConfigured);
        }
        $now = Carbon::now()->getTimestamp();
        try {
            // iat 60 s in the past absorbs clock drift; GitHub accepts at most 10 minutes.
            return JWT::encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $this->settings->appId], $this->settings->privateKey, 'RS256');
        } catch (Throwable) {
            throw new GitHubException(GitHubError::NotConfigured);
        }
    }

    /**
     * An installation token for one repository, read-only. Held in memory by the caller only.
     *
     * @throws GitHubException
     */
    public function installationToken(int $installationId, int $repositoryId): string
    {
        $data = $this->http->postJson("/app/installations/{$installationId}/access_tokens", $this->appJwt(), [
            'repository_ids' => [$repositoryId],
            'permissions' => self::INSTALLATION_PERMISSIONS,
        ]);
        $token = is_array($data) ? ($data['token'] ?? null) : null;
        if (! is_string($token) || $token === '' || strlen($token) > 1024) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return $token;
    }

    /**
     * The installation of the App that covers this repository.
     *
     * @throws GitHubException
     */
    public function repositoryInstallationId(GitHubRepository $repository): int
    {
        $data = $this->http->getJson("/repos/{$repository->path()}/installation", $this->appJwt())['json'];
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (! is_int($id) || $id <= 0) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return $id;
    }

    /**
     * Exchanges an OAuth code for the user's tokens.
     *
     * @throws GitHubException
     */
    public function exchangeCode(string $code): GitHubUserTokens
    {
        return $this->tokenRequest(['code' => $code, 'redirect_uri' => $this->settings->callbackUrl]);
    }

    /**
     * @throws GitHubException
     */
    public function refresh(string $refreshToken): GitHubUserTokens
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * @return array{id: int, login: string}
     *
     * @throws GitHubException
     */
    public function user(string $userToken): array
    {
        $data = $this->http->getJson('/user', $userToken)['json'];
        if (! is_array($data) || ! is_int($data['id'] ?? null) || $data['id'] <= 0 || ! GitHubNames::isLogin($data['login'] ?? null)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return ['id' => $data['id'], 'login' => $data['login']];
    }

    /**
     * The App's installations this user can access (first 100).
     *
     * @return list<array{id: int, account: string, account_type: string, repository_selection: string}>
     *
     * @throws GitHubException
     */
    public function installations(string $userToken): array
    {
        $data = $this->http->getJson('/user/installations', $userToken, ['per_page' => self::MAX_PER_PAGE])['json'];
        if (! is_array($data) || ! is_array($data['installations'] ?? null)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }
        $installations = [];
        foreach (array_slice($data['installations'], 0, self::MAX_PER_PAGE) as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $login = is_array($item) && is_array($item['account'] ?? null) ? ($item['account']['login'] ?? null) : null;
            $type = is_array($item) && is_array($item['account'] ?? null) ? ($item['account']['type'] ?? null) : null;
            $selection = is_array($item) ? ($item['repository_selection'] ?? null) : null;
            if (! is_int($id) || $id <= 0 || ! GitHubNames::isLogin($login) || ! in_array($type, ['User', 'Organization'], true)
                || ! in_array($selection, ['all', 'selected'], true)) {
                throw new GitHubException(GitHubError::InvalidResponse);
            }
            $installations[] = ['id' => $id, 'account' => $login, 'account_type' => $type, 'repository_selection' => $selection];
        }

        return $installations;
    }

    /**
     * One page of the repositories this user can access in an installation.
     * GitHub answers 403/404 when the installation is not the user's.
     *
     * @return array{repositories: list<GitHubRepository>, has_more: bool}
     *
     * @throws GitHubException
     */
    public function installationRepositories(string $userToken, int $installationId, int $page, int $perPage): array
    {
        $result = $this->http->getJson("/user/installations/{$installationId}/repositories", $userToken, [
            'per_page' => min(self::MAX_PER_PAGE, max(1, $perPage)),
            'page' => max(1, $page),
        ]);
        $data = $result['json'];
        if (! is_array($data) || ! is_array($data['repositories'] ?? null)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return [
            'repositories' => array_map(GitHubRepository::fromApi(...), array_values(array_slice($data['repositories'], 0, self::MAX_PER_PAGE))),
            'has_more' => $result['next'],
        ];
    }

    /**
     * A repository by its numeric ID, as seen with the given token.
     *
     * @throws GitHubException
     */
    public function repository(string $token, int $repositoryId): GitHubRepository
    {
        $repository = GitHubRepository::fromApi($this->http->getJson("/repositories/{$repositoryId}", $token)['json']);
        if ($repository->id !== $repositoryId) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return $repository;
    }

    /**
     * @return array{branches: list<array{name: string, protected: bool}>, has_more: bool}
     *
     * @throws GitHubException
     */
    public function branches(string $token, GitHubRepository $repository, int $page, int $perPage): array
    {
        $result = $this->http->getJson("/repos/{$repository->path()}/branches", $token, [
            'per_page' => min(self::MAX_PER_PAGE, max(1, $perPage)),
            'page' => max(1, $page),
        ]);
        if (! is_array($result['json']) || ! array_is_list($result['json'])) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }
        $branches = [];
        foreach (array_slice($result['json'], 0, self::MAX_PER_PAGE) as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;
            // Names CodeDNA cannot use safely are left out rather than passed on.
            if (GitHubNames::isBranch($name)) {
                $branches[] = ['name' => $name, 'protected' => ($item['protected'] ?? false) === true];
            }
        }

        return ['branches' => $branches, 'has_more' => $result['next']];
    }

    /**
     * The commit a branch points to now.
     *
     * @throws GitHubException
     */
    public function branchHead(string $token, GitHubRepository $repository, string $branch): string
    {
        if (! GitHubNames::isBranch($branch)) {
            throw new GitHubException(GitHubError::NotFound);
        }
        $data = $this->http->getJson("/repos/{$repository->path()}/branches/".GitHubNames::branchPath($branch), $token)['json'];
        $sha = is_array($data) && is_array($data['commit'] ?? null) ? ($data['commit']['sha'] ?? null) : null;
        if (! is_array($data) || ($data['name'] ?? null) !== $branch || ! GitHubNames::isCommitSha($sha)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }

        return $sha;
    }

    /**
     * Downloads the ZIP archive of one exact commit into $destination. GitHub
     * redirects to a short-lived download URL; only an allowed origin is fetched.
     *
     * @throws GitHubException
     */
    public function downloadArchive(string $installationToken, GitHubRepository $repository, string $commitSha, string $destination, int $maxBytes): void
    {
        if (! GitHubNames::isCommitSha($commitSha)) {
            throw new GitHubException(GitHubError::InvalidResponse);
        }
        $location = $this->http->redirectLocation("/repos/{$repository->path()}/zipball/{$commitSha}", $installationToken);
        $this->http->download($location, $destination, $maxBytes);
    }

    /**
     * POST {web_url}/login/oauth/access_token. GitHub reports a bad code or
     * refresh token as HTTP 200 with an "error" field.
     *
     * @param  array<string, string>  $form
     *
     * @throws GitHubException
     */
    private function tokenRequest(array $form): GitHubUserTokens
    {
        if (! $this->settings->configured()) {
            throw new GitHubException(GitHubError::NotConfigured);
        }
        $data = $this->http->postWebForm('/login/oauth/access_token', [
            'client_id' => $this->settings->clientId,
            'client_secret' => $this->settings->clientSecret,
            ...$form,
        ]);
        if (is_array($data) && isset($data['error'])) {
            throw new GitHubException(GitHubError::Unauthorized);
        }

        return GitHubUserTokens::fromApi($data);
    }
}
