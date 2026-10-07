<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A GitHub test double behind Laravel's HTTP fake: the real GitHubHttp,
 * GitHubApi and actions run against it. It checks credentials like GitHub
 * does (the App JWT's RS256 signature, the user token, the installation
 * token) and records every request so tests can assert what was sent.
 *
 * Configure with configure(), adjust the public state, then fake().
 */
final class FakeGitHub
{
    public const API = 'https://api.github.test';

    public const WEB = 'https://github.test';

    public const CODELOAD = 'https://codeload.github.test';

    public const APP_ID = '123456';

    public const CLIENT_ID = 'Iv1.testclient';

    public const CLIENT_SECRET = 'test-client-secret-value';

    public const USER_TOKEN = 'ghu_UserAccessTokenSecretValue';

    public const REFRESH_TOKEN = 'ghr_RefreshTokenSecretValue';

    public const INSTALLATION_TOKEN = 'ghs_InstallationTokenSecretValue';

    public const DOWNLOAD_TOKEN = 'DownloadUrlTokenSecretValue';

    public const GOOD_CODE = 'good-oauth-code';

    public const INSTALLATION_ID = 77;

    public const REPOSITORY_ID = 1296269;

    public const SHA = '6dcb09b5b57875f334f61aebed695e2e4193db5e';

    public string $publicKey;

    public string $privateKey;

    /** @var array<int, array<string, mixed>> repositories by ID, in the GitHub API shape */
    public array $repositories = [];

    /** @var array<int, array<string, string>> branch name => head commit, by repository ID */
    public array $branches = [];

    /** @var array<string, string> ZIP bytes by commit */
    public array $archives = [];

    /** @var list<int> installations the user can access */
    public array $userInstallations = [self::INSTALLATION_ID];

    /** @var (callable(Request): (PromiseInterface|null))|null answers first when it returns a response */
    public $override = null;

    /** @var list<Request> */
    public array $requests = [];

    public int $userTokenExpiresIn = 28800;

    public string $issuedUserToken = self::USER_TOKEN;

    public static function configure(): self
    {
        $fake = new self;
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        assert($key !== false);
        openssl_pkey_export($key, $private);
        $fake->privateKey = (string) $private;
        $fake->publicKey = (string) openssl_pkey_get_details($key)['key'];

        config([
            'codedna.github.app_id' => self::APP_ID,
            'codedna.github.app_slug' => 'codedna-test',
            'codedna.github.client_id' => self::CLIENT_ID,
            'codedna.github.client_secret' => self::CLIENT_SECRET,
            'codedna.github.private_key' => $fake->privateKey,
            'codedna.github.private_key_path' => '',
            'codedna.github.api_url' => self::API,
            'codedna.github.web_url' => self::WEB,
            'codedna.github.archive_origins' => [self::CODELOAD],
            'codedna.github.callback_url' => 'http://localhost/app/github/callback',
            'codedna.github.retry_delay_ms' => 0,
        ]);
        $fake->repository(self::REPOSITORY_ID, 'octo-org', 'billing-service');
        $fake->archives[self::SHA] = (new ZipBuilder)
            ->file('octo-org-billing-service-6dcb09b/app.py', "def main():\n    return 1\n")
            ->file('octo-org-billing-service-6dcb09b/README.md', '# billing')
            ->comment(self::SHA)
            ->build();

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function repository(int $id, string $owner, string $name, array $overrides = [], string $branch = 'main', string $sha = self::SHA): void
    {
        $this->repositories[$id] = $overrides + [
            'id' => $id,
            'node_id' => 'R_kgDO'.$id,
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'owner' => ['login' => $owner, 'id' => 1, 'type' => 'Organization'],
            'private' => true,
            'archived' => false,
            'disabled' => false,
            'default_branch' => $branch,
            'url' => self::API."/repos/{$owner}/{$name}",
            'clone_url' => "https://github.test/{$owner}/{$name}.git",
            'permissions' => ['admin' => false, 'push' => true, 'pull' => true],
        ];
        $this->branches[$id] = [$branch => $sha];
    }

    public function fake(): self
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->requests[] = $request;
            if ($this->override !== null && ($answer = ($this->override)($request)) !== null) {
                return $answer;
            }

            return $this->respond($request);
        });

        return $this;
    }

    /**
     * @return list<Request>
     */
    public function sent(string $method, string $pathPattern): array
    {
        return array_values(array_filter($this->requests, fn (Request $r): bool => $r->method() === $method
            && preg_match($pathPattern, (string) parse_url($r->url(), PHP_URL_PATH)) === 1));
    }

    private function respond(Request $request): PromiseInterface
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $method = $request->method();

        if (str_starts_with($url, self::WEB.'/login/oauth/access_token') && $method === 'POST') {
            return $this->token($request);
        }
        if (str_starts_with($url, self::CODELOAD.'/')) {
            return $this->codeload($request, $path);
        }
        if (! str_starts_with($url, self::API.'/')) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        if ($method === 'POST' && preg_match('#^/app/installations/(\d+)/access_tokens$#', $path, $m) === 1) {
            if (! $this->validJwt($request)) {
                return Http::response(['message' => 'A JSON web token could not be decoded'], 401);
            }
            if ((int) $m[1] !== self::INSTALLATION_ID) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            return Http::response(['token' => self::INSTALLATION_TOKEN, 'expires_at' => '2030-01-01T00:00:00Z', 'permissions' => ['contents' => 'read', 'metadata' => 'read']], 201);
        }
        if ($method === 'GET' && preg_match('#^/repos/([^/]+)/([^/]+)/installation$#', $path, $m) === 1) {
            if (! $this->validJwt($request)) {
                return Http::response(['message' => 'A JSON web token could not be decoded'], 401);
            }

            return $this->byName($m[1], $m[2]) === null
                ? Http::response(['message' => 'Not Found'], 404)
                : Http::response(['id' => self::INSTALLATION_ID, 'account' => ['login' => $m[1]]]);
        }

        $token = $this->bearer($request);
        $asUser = $token === $this->issuedUserToken;
        $asInstallation = $token === self::INSTALLATION_TOKEN;
        if (! $asUser && ! $asInstallation) {
            return Http::response(['message' => 'Bad credentials'], 401);
        }

        if ($method === 'GET' && $path === '/user' && $asUser) {
            return Http::response(['id' => 4242, 'login' => 'octo-dev', 'name' => 'Octo Dev', 'email' => 'octo@example.invalid']);
        }
        if ($method === 'GET' && $path === '/user/installations' && $asUser) {
            return Http::response(['total_count' => count($this->userInstallations), 'installations' => array_map(fn (int $id): array => [
                'id' => $id, 'account' => ['login' => 'octo-org', 'type' => 'Organization'], 'repository_selection' => 'selected',
                'access_tokens_url' => self::API."/app/installations/{$id}/access_tokens",
            ], $this->userInstallations)]);
        }
        if ($method === 'GET' && preg_match('#^/user/installations/(\d+)/repositories$#', $path, $m) === 1 && $asUser) {
            if (! in_array((int) $m[1], $this->userInstallations, true)) {
                return Http::response(['message' => 'Not Found'], 404);
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $perPage = (int) ($query['per_page'] ?? 30);
            $page = (int) ($query['page'] ?? 1);
            $all = array_values($this->repositories);
            $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
            $headers = count($all) > $page * $perPage ? ['Link' => '<'.self::API.'/user/installations/'.$m[1].'/repositories?page='.($page + 1).'>; rel="next"'] : [];

            return Http::response(['total_count' => count($all), 'repositories' => $slice], 200, $headers);
        }
        if ($method === 'GET' && preg_match('#^/repositories/(\d+)$#', $path, $m) === 1) {
            $repository = $this->repositories[(int) $m[1]] ?? null;

            return $repository === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($repository);
        }
        if ($method === 'GET' && preg_match('#^/repos/([^/]+)/([^/]+)/branches$#', $path, $m) === 1) {
            $repository = $this->byName($m[1], $m[2]);
            if ($repository === null) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            return Http::response(array_map(fn (string $name, string $sha): array => ['name' => $name, 'commit' => ['sha' => $sha], 'protected' => $name === 'main'],
                array_keys($this->branches[$repository['id']]), array_values($this->branches[$repository['id']])));
        }
        if ($method === 'GET' && preg_match('#^/repos/([^/]+)/([^/]+)/branches/(.+)$#', $path, $m) === 1) {
            $repository = $this->byName($m[1], $m[2]);
            $branch = rawurldecode($m[3]);
            $sha = $repository === null ? null : ($this->branches[$repository['id']][$branch] ?? null);

            return $sha === null ? Http::response(['message' => 'Branch not found'], 404) : Http::response(['name' => $branch, 'commit' => ['sha' => $sha], 'protected' => false]);
        }
        if ($method === 'GET' && preg_match('#^/repos/([^/]+)/([^/]+)/zipball/([0-9a-f]{40})$#', $path, $m) === 1 && $asInstallation) {
            return $this->byName($m[1], $m[2]) === null || ! isset($this->archives[$m[3]])
                ? Http::response(['message' => 'Not Found'], 404)
                : Http::response('', 302, ['Location' => self::CODELOAD."/{$m[1]}/{$m[2]}/legacy.zip/{$m[3]}?token=".self::DOWNLOAD_TOKEN]);
        }

        return Http::response(['message' => 'Not Found'], 404);
    }

    private function token(Request $request): PromiseInterface
    {
        $data = $request->data();
        if (($data['client_id'] ?? null) !== self::CLIENT_ID || ($data['client_secret'] ?? null) !== self::CLIENT_SECRET) {
            return Http::response(['error' => 'incorrect_client_credentials']);
        }
        $valid = ($data['code'] ?? null) === self::GOOD_CODE
            || (($data['grant_type'] ?? null) === 'refresh_token' && ($data['refresh_token'] ?? null) === self::REFRESH_TOKEN);
        if (! $valid) {
            return Http::response(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.']);
        }

        return Http::response([
            'access_token' => $this->issuedUserToken,
            'expires_in' => $this->userTokenExpiresIn,
            'refresh_token' => self::REFRESH_TOKEN,
            'refresh_token_expires_in' => 15897600,
            'token_type' => 'bearer',
            'scope' => '',
        ]);
    }

    private function codeload(Request $request, string $path): PromiseInterface
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (($query['token'] ?? null) !== self::DOWNLOAD_TOKEN || $request->hasHeader('Authorization')) {
            return Http::response('Forbidden', 403);
        }
        if (preg_match('#/legacy\.zip/([0-9a-f]{40})$#', $path, $m) !== 1 || ! isset($this->archives[$m[1]])) {
            return Http::response('Not Found', 404);
        }

        return Http::response($this->archives[$m[1]], 200, ['Content-Type' => 'application/zip']);
    }

    private function validJwt(Request $request): bool
    {
        try {
            $claims = JWT::decode($this->bearer($request), new Key($this->publicKey, 'RS256'));
        } catch (Throwable) {
            return false;
        }

        return ($claims->iss ?? null) === self::APP_ID && ($claims->exp ?? 0) - ($claims->iat ?? 0) <= 600;
    }

    private function bearer(Request $request): string
    {
        $header = $request->header('Authorization')[0] ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function byName(string $owner, string $name): ?array
    {
        foreach ($this->repositories as $repository) {
            if ($repository['full_name'] === "{$owner}/{$name}") {
                return $repository;
            }
        }

        return null;
    }
}
