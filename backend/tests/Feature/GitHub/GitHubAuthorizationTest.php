<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Models\GitHubAccount;
use App\Models\GitHubOAuthState;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

/**
 * GitHub authorization (Phase 19): single-use, user-bound, short-lived
 * states; server-side code exchange; encrypted tokens that never leave the
 * server; and installations/repositories read from GitHub as the user.
 */
final class GitHubAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    private User $user;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->github = FakeGitHub::configure()->fake();
        $this->user = User::factory()->create();
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function start(array $body = [], ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->user)->postJson('/api/v1/github/authorizations', $body);
    }

    private function state(?User $as = null): string
    {
        $url = $this->start([], $as)->assertCreated()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function complete(string $state, string $code = FakeGitHub::GOOD_CODE, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->user)->postJson('/api/v1/github/callback', ['state' => $state, 'code' => $code]);
    }

    private function authorize(): void
    {
        $this->complete($this->state())->assertOk();
    }

    /** Everything a response or log line must never contain. */
    private function assertNoSecrets(string $text): void
    {
        foreach ([FakeGitHub::USER_TOKEN, FakeGitHub::REFRESH_TOKEN, FakeGitHub::INSTALLATION_TOKEN, FakeGitHub::CLIENT_SECRET, FakeGitHub::DOWNLOAD_TOKEN, 'BEGIN', 'PRIVATE KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_starting_returns_github_urls_with_a_stored_hashed_single_use_state(): void
    {
        $data = $this->start()->assertCreated()->json('data');

        $this->assertStringStartsWith(FakeGitHub::WEB.'/login/oauth/authorize?', $data['authorize_url']);
        $this->assertStringStartsWith(FakeGitHub::WEB.'/apps/codedna-test/installations/new?state=', $data['install_url']);
        parse_str((string) parse_url($data['authorize_url'], PHP_URL_QUERY), $query);
        $this->assertSame(FakeGitHub::CLIENT_ID, $query['client_id']);
        $this->assertSame('http://localhost/app/github/callback', $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
        $this->assertArrayNotHasKey('client_secret', $query);

        $stored = GitHubOAuthState::query()->sole();
        $this->assertSame(hash('sha256', $query['state']), $stored->state_hash);
        $this->assertSame($this->user->id, $stored->user_id);
        $this->assertNull($stored->consumed_at);
        $this->assertEqualsWithDelta(600, $stored->expires_at->diffInSeconds($stored->created_at, true), 1);
        $this->assertStringNotContainsString($query['state'], (string) json_encode(DB::table('github_oauth_states')->get()));
        $this->assertNotSame($query['state'], $this->state(), 'unpredictable: every state is new');
    }

    public function test_a_valid_state_and_code_store_encrypted_tokens(): void
    {
        $response = $this->complete($this->state())->assertOk();

        $response->assertExactJson(['data' => ['connected' => true, 'project_id' => null]]);
        $account = GitHubAccount::query()->sole();
        $this->assertSame([4242, 'octo-dev'], [$account->github_user_id, $account->login]);
        $this->assertSame(FakeGitHub::USER_TOKEN, $account->access_token);
        $this->assertSame(FakeGitHub::REFRESH_TOKEN, $account->refresh_token);
        // Encrypted at rest: the raw columns never hold the tokens.
        $raw = (array) DB::table('github_accounts')->first();
        $this->assertNotSame(FakeGitHub::USER_TOKEN, $raw['access_token']);
        $this->assertNoSecrets((string) json_encode($raw));
        $this->assertSame(FakeGitHub::USER_TOKEN, decrypt($raw['access_token'], false));
        $this->assertNoSecrets((string) json_encode($account->toArray()));
        // The code was exchanged server-side with the client secret, sent to the configured web origin only.
        $exchange = $this->github->sent('POST', '#^/login/oauth/access_token$#')[0];
        $this->assertSame(FakeGitHub::GOOD_CODE, $exchange->data()['code']);
        $this->assertStringStartsWith(FakeGitHub::WEB, $exchange->url());

        $this->asUser($this->user)->getJson('/api/v1/github')->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.account.login', 'octo-dev');
    }

    public function test_a_state_is_single_use(): void
    {
        $state = $this->state();
        $this->complete($state)->assertOk();

        $this->complete($state)->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_STATE_INVALID');
        $this->assertCount(1, $this->github->sent('POST', '#access_token#'), 'a replay never reaches GitHub');
    }

    public function test_an_expired_state_is_refused(): void
    {
        $state = $this->state();
        $this->travel(601)->seconds();

        $this->complete($state)->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_STATE_INVALID');
        $this->assertSame(0, GitHubAccount::query()->count());
    }

    public function test_a_state_belongs_to_the_user_who_started_it(): void
    {
        $state = $this->state();
        $other = User::factory()->create();

        $this->complete($state, as: $other)->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_STATE_INVALID');
        $this->assertSame(0, GitHubAccount::query()->count());
        // Not consumed by the other user's attempt: the owner can still finish.
        $this->complete($state)->assertOk();
    }

    public function test_unknown_or_malformed_states_and_codes_are_refused(): void
    {
        $this->complete(str_repeat('a', 43))->assertStatus(422)->assertJsonPath('error.code', 'GITHUB_STATE_INVALID');
        $this->complete('short')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->complete($this->state(), 'bad code; rm -rf /')->assertUnprocessable();
        $this->asUser($this->user)->postJson('/api/v1/github/callback', ['state' => $this->state(), 'code' => 'x', 'access_token' => 'injected'])->assertUnprocessable();
        $this->assertSame(0, GitHubAccount::query()->count());
    }

    public function test_a_code_github_refuses_consumes_the_state_and_stores_nothing(): void
    {
        $state = $this->state();

        $this->complete($state, 'wrong-code')->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_AUTH_REQUIRED');
        $this->complete($state)->assertStatus(422);
        $this->assertSame(0, GitHubAccount::query()->count());
    }

    public function test_the_callback_ignores_a_client_installation_id(): void
    {
        $state = $this->state();

        $this->asUser($this->user)->postJson('/api/v1/github/callback', ['state' => $state, 'code' => FakeGitHub::GOOD_CODE, 'installation_id' => '999999', 'setup_action' => 'install'])->assertOk();
        $this->assertSame([], $this->github->sent('GET', '#999999#'));
    }

    public function test_a_return_project_must_be_the_users_own(): void
    {
        $mine = Project::factory()->for($this->user)->create();
        $theirs = Project::factory()->create();

        $this->start(['project_id' => $theirs->id])->assertNotFound();
        $this->start(['project_id' => $mine->id, 'redirect' => 'https://evil.test'])->assertUnprocessable();
        $state = $this->start(['project_id' => $mine->id])->assertCreated()->json('data.install_url');
        parse_str((string) parse_url($state, PHP_URL_QUERY), $query);

        $this->complete($query['state'])->assertOk()->assertJsonPath('data.project_id', $mine->id);
    }

    public function test_nothing_works_when_github_is_not_configured(): void
    {
        config(['codedna.github.client_secret' => '']);

        $this->start()->assertStatus(503)->assertJsonPath('error.code', 'GITHUB_NOT_CONFIGURED');
        $this->asUser($this->user)->getJson('/api/v1/github')->assertOk()->assertJsonPath('data.configured', false);
        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertStatus(503);
    }

    public function test_authentication_is_required(): void
    {
        $this->postJson('/api/v1/github/authorizations')->assertUnauthorized();
        $this->postJson('/api/v1/github/callback', ['state' => str_repeat('a', 43), 'code' => 'x'])->assertUnauthorized();
        $this->getJson('/api/v1/github')->assertUnauthorized();
        $this->getJson('/api/v1/github/installations')->assertUnauthorized();
    }

    public function test_installations_and_repositories_are_read_from_github_as_the_user(): void
    {
        $this->authorize();
        foreach (range(1, 3) as $i) {
            $this->github->repository(1000 + $i, 'octo-org', "service-{$i}", ['private' => $i % 2 === 0, 'archived' => $i === 3]);
        }

        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertOk()
            ->assertExactJson(['data' => [['id' => FakeGitHub::INSTALLATION_ID, 'account' => 'octo-org', 'account_type' => 'Organization', 'repository_selection' => 'selected']]]);

        $first = $this->asUser($this->user)->getJson('/api/v1/github/installations/'.FakeGitHub::INSTALLATION_ID.'/repositories?per_page=2')->assertOk();
        $first->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'has_more' => true]);
        $this->assertSame(['id', 'owner', 'name', 'full_name', 'private', 'archived', 'default_branch'], array_keys($first->json('data.0')));
        $this->assertSame(['billing-service', 'service-1'], array_column($first->json('data'), 'name'));
        $last = $this->asUser($this->user)->getJson('/api/v1/github/installations/'.FakeGitHub::INSTALLATION_ID.'/repositories?per_page=2&page=2')->assertOk();
        $this->assertSame([false, true], [$last->json('meta.has_more'), $last->json('data.1.archived')]);

        foreach ($this->github->sent('GET', '#^/user/installations#') as $request) {
            $this->assertSame('Bearer '.FakeGitHub::USER_TOKEN, $request->header('Authorization')[0]);
        }
        $this->assertNoSecrets((string) $first->getContent());
        $this->assertStringNotContainsString('clone_url', (string) $first->getContent());
        $this->assertStringNotContainsString(FakeGitHub::API, (string) $first->getContent());
    }

    public function test_another_installation_is_not_listed(): void
    {
        $this->authorize();

        $this->asUser($this->user)->getJson('/api/v1/github/installations/999999/repositories')->assertNotFound();
        $this->asUser($this->user)->getJson('/api/v1/github/installations/abc/repositories')->assertNotFound();
        $this->asUser($this->user)->getJson('/api/v1/github/installations/99999999999999999999/repositories')->assertNotFound();
        $this->asUser($this->user)->getJson('/api/v1/github/installations/0/repositories')->assertNotFound();
        $this->asUser($this->user)->getJson('/api/v1/github/installations/'.FakeGitHub::INSTALLATION_ID.'/repositories?per_page=101')->assertUnprocessable();
        $this->asUser($this->user)->getJson('/api/v1/github/installations/'.FakeGitHub::INSTALLATION_ID.'/repositories?page=51')->assertUnprocessable();
    }

    public function test_browsing_without_authorization_asks_for_it(): void
    {
        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_AUTH_REQUIRED');
        $this->assertSame([], $this->github->requests);
    }

    public function test_an_expiring_user_token_is_refreshed_once_and_rotated(): void
    {
        $this->github->userTokenExpiresIn = 120;
        $this->authorize();
        $this->travel(90)->seconds();
        $this->github->issuedUserToken = 'ghu_RotatedUserAccessToken';

        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertOk();
        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertOk();

        $refreshes = array_filter($this->github->sent('POST', '#access_token#'), fn (Request $r): bool => ($r->data()['grant_type'] ?? null) === 'refresh_token');
        $this->assertCount(1, $refreshes);
        $this->assertSame('ghu_RotatedUserAccessToken', GitHubAccount::query()->sole()->access_token);
    }

    public function test_a_refused_refresh_asks_for_authorization_again(): void
    {
        $this->github->userTokenExpiresIn = 30;
        $this->authorize();
        $this->github->override = fn (Request $r) => str_contains($r->url(), 'access_token') ? Http::response(['error' => 'bad_refresh_token']) : null;

        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertStatus(409)->assertJsonPath('error.code', 'GITHUB_AUTH_REQUIRED');
    }

    public function test_github_failures_become_safe_codes(): void
    {
        $this->authorize();
        $cases = [
            [Http::response(['message' => 'API rate limit exceeded for user. token ghu_leak'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 120)]), 429, 'GITHUB_RATE_LIMITED'],
            [Http::response(['message' => 'secondary rate limit'], 429, ['Retry-After' => '30']), 429, 'GITHUB_RATE_LIMITED'],
            [Http::response(['message' => 'Bad credentials ghu_leak'], 401), 409, 'GITHUB_AUTH_REQUIRED'],
            [Http::response('<html>Unicorn ghu_leak</html>', 500), 503, 'GITHUB_UNAVAILABLE'],
            [Http::response('{not json', 200), 503, 'GITHUB_UNAVAILABLE'],
            [Http::response(['installations' => [['id' => 'x']]], 200), 503, 'GITHUB_UNAVAILABLE'],
        ];
        foreach ($cases as [$answer, $status, $code]) {
            $this->github->override = fn () => $answer;
            $response = $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertStatus($status)->assertJsonPath('error.code', $code);
            $this->assertStringNotContainsString('ghu_leak', (string) $response->getContent());
            $this->assertStringNotContainsString('Unicorn', (string) $response->getContent());
        }
        $this->asUser($this->user)->getJson('/api/v1/github/installations');
        $this->github->override = fn () => Http::response(['message' => 'x'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 120)]);
        $this->asUser($this->user)->getJson('/api/v1/github/installations')->assertHeader('Retry-After');
    }

    public function test_unlinking_deletes_the_tokens_only(): void
    {
        $this->authorize();

        $this->asUser($this->user)->deleteJson('/api/v1/github')->assertNoContent();

        $this->assertSame(0, GitHubAccount::query()->count());
        $this->asUser($this->user)->getJson('/api/v1/github')->assertJsonPath('data.account', null);
    }

    public function test_tokens_never_appear_in_responses_or_logs(): void
    {
        $state = $this->state();
        $bodies = [(string) $this->complete($state)->getContent()];
        foreach (['/api/v1/github', '/api/v1/github/installations', '/api/v1/github/installations/'.FakeGitHub::INSTALLATION_ID.'/repositories'] as $path) {
            $bodies[] = (string) $this->asUser($this->user)->getJson($path)->getContent();
        }
        $this->github->override = fn () => Http::response(['message' => 'boom'], 500);
        $bodies[] = (string) $this->asUser($this->user)->getJson('/api/v1/github/installations')->getContent();

        foreach ($bodies as $body) {
            $this->assertNoSecrets($body);
            $this->assertStringNotContainsString($state, $body);
        }
        $logged = (string) json_encode(array_map(fn (MessageLogged $l): array => [$l->message, $l->context], $this->logs));
        $this->assertNoSecrets($logged);
        $this->assertStringNotContainsString($state, $logged);
        $this->assertStringNotContainsString(FakeGitHub::GOOD_CODE, $logged);
    }
}
