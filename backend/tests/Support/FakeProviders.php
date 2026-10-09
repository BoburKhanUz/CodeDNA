<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * GitLab and Bitbucket Cloud test doubles behind Laravel's HTTP fake
 * (Phase 28): the real ProviderHttp, adapters and actions run against them.
 * They check credentials the way the providers do (client secret on the
 * token endpoints, the Bearer token on the API and archive endpoints) and
 * record every request so tests can assert what was, and was not, sent.
 *
 * Deterministic, offline, and never a real provider: no live GitLab or
 * Bitbucket is contacted by the test suite.
 */
final class FakeProviders
{
    public const GITLAB = 'https://gitlab.test';

    public const BITBUCKET_API = 'https://api.bitbucket.test';

    public const BITBUCKET_WEB = 'https://bitbucket.test';

    public const CLIENT_ID = 'test-client-id';

    public const CLIENT_SECRET = 'test-client-secret-value';

    public const GOOD_CODE = 'good-oauth-code';

    public const ACCESS_TOKEN = 'provider-access-token-secret';

    public const REFRESH_TOKEN = 'provider-refresh-token-secret';

    public const REFRESHED_TOKEN = 'provider-refreshed-token-secret';

    public const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    public const GITLAB_PROJECT = 4242;

    public const BB_WORKSPACE = '{11111111-2222-3333-4444-555555555555}';

    public const BB_REPOSITORY = '{66666666-7777-8888-9999-000000000000}';

    /** Provider user IDs returned by /user. */
    public string $gitlabUserId = '9001';

    public string $bitbucketUserId = '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}';

    /** @var array<int, array<string, mixed>> GitLab projects by ID */
    public array $gitlabProjects = [];

    /** @var array<string, array<string, mixed>> Bitbucket repositories by repository UUID */
    public array $bitbucketRepositories = [];

    /** @var array<string, array<string, string>> branch => sha, by repository key ("gitlab:4242" / "bitbucket:{uuid}") */
    public array $branches = [];

    /** @var array<string, string> ZIP bytes by commit */
    public array $archives = [];

    /** Tokens the fake accepts on API calls. @var list<string> */
    public array $validTokens = [self::ACCESS_TOKEN, self::REFRESHED_TOKEN];

    public int $expiresIn = 7200;

    public int $revocations = 0;

    /** Repositories per page the fake serves before reporting a next page. */
    public int $pageSize = 100;

    /** @var (callable(Request): (PromiseInterface|null))|null answers first when it returns a response */
    public $override = null;

    /** @var list<Request> */
    public array $requests = [];

    public static function configure(): self
    {
        config([
            'codedna.repository_providers.gitlab.client_id' => self::CLIENT_ID,
            'codedna.repository_providers.gitlab.client_secret' => self::CLIENT_SECRET,
            'codedna.repository_providers.gitlab.base_url' => self::GITLAB,
            'codedna.repository_providers.gitlab.callback_url' => 'https://app.codedna.test/app/integrations/gitlab/callback',
            'codedna.repository_providers.bitbucket.client_id' => self::CLIENT_ID,
            'codedna.repository_providers.bitbucket.client_secret' => self::CLIENT_SECRET,
            'codedna.repository_providers.bitbucket.api_url' => self::BITBUCKET_API,
            'codedna.repository_providers.bitbucket.web_url' => self::BITBUCKET_WEB,
            'codedna.repository_providers.bitbucket.archive_origins' => [self::BITBUCKET_WEB],
            'codedna.repository_providers.bitbucket.callback_url' => 'https://app.codedna.test/app/integrations/bitbucket/callback',
            'codedna.github.retry_delay_ms' => 0,
        ]);
        $fake = new self;
        $fake->gitlabProjects[self::GITLAB_PROJECT] = self::gitlabProject(self::GITLAB_PROJECT, 'acme/billing-service', 'private');
        $fake->bitbucketRepositories[self::BB_REPOSITORY] = self::bitbucketRepository(self::BB_REPOSITORY, 'billing-service', true);
        $fake->branches['gitlab:'.self::GITLAB_PROJECT] = ['main' => self::SHA];
        $fake->branches['bitbucket:'.self::BB_REPOSITORY] = ['main' => self::SHA];
        $fake->archives[self::SHA] = (new ZipBuilder)->file('acme-billing-service-a1b2c3d/app/main.py', "print('hello')\n")->comment(self::SHA)->build();

        return $fake;
    }

    /** @return array<string, mixed> */
    public static function gitlabProject(int $id, string $path, string $visibility, ?string $default = 'main'): array
    {
        return ['id' => $id, 'path_with_namespace' => $path, 'name' => basename($path), 'visibility' => $visibility, 'archived' => false, 'default_branch' => $default];
    }

    /** @return array<string, mixed> */
    public static function bitbucketRepository(string $uuid, string $slug, bool $private, ?string $main = 'main'): array
    {
        return ['uuid' => $uuid, 'slug' => $slug, 'full_name' => 'acme/'.$slug, 'is_private' => $private,
            'workspace' => ['uuid' => self::BB_WORKSPACE, 'slug' => 'acme'], 'mainbranch' => $main === null ? null : ['name' => $main]];
    }

    public static function bitbucketId(string $repository = self::BB_REPOSITORY): string
    {
        return self::BB_WORKSPACE.'/'.$repository;
    }

    public function fake(): self
    {
        Http::fake(function (Request $request) {
            $this->requests[] = $request;
            if ($this->override !== null && ($answer = ($this->override)($request)) !== null) {
                return $answer;
            }

            return $this->answer($request);
        });

        return $this;
    }

    /** @return list<Request> */
    public function requestsTo(string $needle): array
    {
        return array_values(array_filter($this->requests, static fn (Request $r): bool => str_contains($r->url(), $needle)));
    }

    private function answer(Request $request): ?PromiseInterface
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            str_starts_with($url, self::GITLAB.'/oauth/token') => $this->token($request, gitlab: true),
            str_starts_with($url, self::GITLAB.'/oauth/revoke') => $this->revoke($request),
            str_starts_with($url, self::BITBUCKET_WEB.'/site/oauth2/access_token') => $this->token($request, gitlab: false),
            str_starts_with($url, self::GITLAB.'/api/v4/') => $this->authorized($request) ?? $this->gitlab(substr($path, strlen('/api/v4')), $query),
            str_starts_with($url, self::BITBUCKET_API.'/2.0/') => $this->authorized($request) ?? $this->bitbucket(rawurldecode(substr($path, strlen('/2.0'))), $query),
            str_starts_with($url, self::BITBUCKET_WEB.'/') && str_contains($path, '/get/') => $this->authorized($request) ?? $this->archive(basename($path, '.zip')),
            // Another host (e.g. FakeGitHub's): let the next fake answer.
            ! in_array(parse_url($url, PHP_URL_HOST), ['gitlab.test', 'api.bitbucket.test', 'bitbucket.test'], true) => null,
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    private function token(Request $request, bool $gitlab): PromiseInterface
    {
        $data = $request->data();
        $credentials = $gitlab
            ? (($data['client_id'] ?? null) === self::CLIENT_ID && ($data['client_secret'] ?? null) === self::CLIENT_SECRET)
            : ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode(self::CLIENT_ID.':'.self::CLIENT_SECRET);
        if (! $credentials) {
            return Http::response(['error' => 'invalid_client'], 401);
        }
        $ok = match ($data['grant_type'] ?? null) {
            'authorization_code' => ($data['code'] ?? null) === self::GOOD_CODE,
            'refresh_token' => ($data['refresh_token'] ?? null) === self::REFRESH_TOKEN,
            default => false,
        };
        if (! $ok) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        return Http::response([
            'access_token' => ($data['grant_type'] === 'refresh_token') ? self::REFRESHED_TOKEN : self::ACCESS_TOKEN,
            'token_type' => 'bearer',
            'expires_in' => $this->expiresIn,
            'refresh_token' => self::REFRESH_TOKEN,
        ]);
    }

    private function revoke(Request $request): PromiseInterface
    {
        $data = $request->data();
        if (($data['client_id'] ?? null) !== self::CLIENT_ID || ($data['client_secret'] ?? null) !== self::CLIENT_SECRET) {
            return Http::response([], 401);
        }
        $this->revocations++;

        return Http::response([], 200);
    }

    private function authorized(Request $request): ?PromiseInterface
    {
        $header = $request->header('Authorization')[0] ?? '';
        foreach ($this->validTokens as $token) {
            if ($header === 'Bearer '.$token) {
                return null;
            }
        }

        return Http::response(['message' => '401 Unauthorized'], 401);
    }

    /** @param  array<string, mixed>  $query */
    private function gitlab(string $path, array $query): PromiseInterface
    {
        if ($path === '/user') {
            return Http::response(['id' => (int) $this->gitlabUserId, 'username' => 'ada.gitlab']);
        }
        if ($path === '/projects') {
            return $this->page(array_values($this->gitlabProjects), (int) ($query['page'] ?? 1), gitlab: true);
        }
        if (preg_match('#^/projects/(\d+)(/.*)?$#', $path, $m) !== 1 || ! isset($this->gitlabProjects[(int) $m[1]])) {
            return Http::response(['message' => '404 Project Not Found'], 404);
        }
        $branches = $this->branches['gitlab:'.$m[1]] ?? [];
        $rest = $m[2] ?? '';

        return match (true) {
            $rest === '' => Http::response($this->gitlabProjects[(int) $m[1]]),
            $rest === '/repository/branches' => Http::response(array_map(static fn (string $n, string $s): array => ['name' => $n, 'commit' => ['id' => $s]], array_keys($branches), $branches)),
            str_starts_with($rest, '/repository/branches/') => isset($branches[rawurldecode(substr($rest, strlen('/repository/branches/')))])
                ? Http::response(['name' => rawurldecode(substr($rest, strlen('/repository/branches/'))), 'commit' => ['id' => $branches[rawurldecode(substr($rest, strlen('/repository/branches/')))]]])
                : Http::response(['message' => '404 Branch Not Found'], 404),
            $rest === '/repository/archive.zip' => $this->archive((string) ($query['sha'] ?? '')),
            default => Http::response([], 404),
        };
    }

    /** @param  array<string, mixed>  $query */
    private function bitbucket(string $path, array $query): PromiseInterface
    {
        if ($path === '/user') {
            return Http::response(['uuid' => $this->bitbucketUserId, 'username' => 'ada-bb']);
        }
        if ($path === '/repositories') {
            return $this->page(array_values($this->bitbucketRepositories), (int) ($query['page'] ?? 1), gitlab: false);
        }
        if (preg_match('#^/repositories/(\{[^}]+\})/(\{[^}]+\})(/.*)?$#', $path, $m) !== 1 || $m[1] !== self::BB_WORKSPACE || ! isset($this->bitbucketRepositories[$m[2]])) {
            return Http::response(['type' => 'error'], 404);
        }
        $branches = $this->branches['bitbucket:'.$m[2]] ?? [];
        $rest = $m[3] ?? '';

        return match (true) {
            $rest === '' => Http::response($this->bitbucketRepositories[$m[2]]),
            $rest === '/refs/branches' => Http::response(['values' => array_map(static fn (string $n, string $s): array => ['name' => $n, 'target' => ['hash' => $s]], array_keys($branches), $branches)]),
            str_starts_with($rest, '/refs/branches/') => isset($branches[substr($rest, strlen('/refs/branches/'))])
                ? Http::response(['name' => substr($rest, strlen('/refs/branches/')), 'target' => ['hash' => $branches[substr($rest, strlen('/refs/branches/'))]]])
                : Http::response(['type' => 'error'], 404),
            default => Http::response([], 404),
        };
    }

    /** @param  list<array<string, mixed>>  $items */
    private function page(array $items, int $page, bool $gitlab): PromiseInterface
    {
        $slice = array_slice($items, ($page - 1) * $this->pageSize, $this->pageSize);
        $more = count($items) > $page * $this->pageSize;

        return $gitlab
            ? Http::response($slice, 200, $more ? ['X-Next-Page' => (string) ($page + 1)] : ['X-Next-Page' => ''])
            : Http::response(['values' => $slice] + ($more ? ['next' => self::BITBUCKET_API.'/2.0/repositories?page='.($page + 1)] : []));
    }

    private function archive(string $sha): PromiseInterface
    {
        return isset($this->archives[$sha])
            ? Http::response($this->archives[$sha], 200, ['Content-Type' => 'application/zip'])
            : Http::response('', 404);
    }
}
