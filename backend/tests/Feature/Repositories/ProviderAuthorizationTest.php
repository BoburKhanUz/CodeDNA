<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Models\Project;
use App\Models\RepositoryProviderAccount;
use App\Models\RepositoryProviderOAuthState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeProviders;
use Tests\Support\ProviderFixtures;
use Tests\TestCase;

/**
 * GitLab and Bitbucket Cloud authorization (Phase 28,
 * docs/integrations/oauth-setup.md): single-use states bound to the user and
 * the provider, server-side code exchange, encrypted tokens, no account
 * take-over, and safe disconnection. Against FakeProviders (no live provider).
 */
final class ProviderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private FakeProviders $providers;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providers = FakeProviders::configure()->fake();
        $this->user = User::factory()->create();
    }

    /** @return iterable<string, array{RepositoryProviderKey}> */
    public static function providers(): iterable
    {
        yield 'GitLab' => [RepositoryProviderKey::GitLab];
        yield 'Bitbucket Cloud' => [RepositoryProviderKey::Bitbucket];
    }

    private function start(RepositoryProviderKey $provider, ?User $as = null, array $body = []): string
    {
        $url = $this->asUser($as ?? $this->user)->postJson("/api/v1/repository-providers/{$provider->value}/authorizations", $body)
            ->assertCreated()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function complete(RepositoryProviderKey $provider, string $state, string $code = FakeProviders::GOOD_CODE, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->user)->postJson("/api/v1/repository-providers/{$provider->value}/callback", ['state' => $state, 'code' => $code]);
    }

    #[DataProvider('providers')]
    public function test_a_valid_callback_links_the_account_with_encrypted_tokens(RepositoryProviderKey $provider): void
    {
        $url = $this->asUser($this->user)->postJson("/api/v1/repository-providers/{$provider->value}/authorizations")->assertCreated()->json('data.authorize_url');
        $origin = $provider === RepositoryProviderKey::GitLab ? FakeProviders::GITLAB : FakeProviders::BITBUCKET_WEB;
        $this->assertStringStartsWith($origin.'/', $url);
        $this->assertStringNotContainsString(FakeProviders::CLIENT_SECRET, $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $response = $this->complete($provider, (string) $query['state'])->assertOk()
            ->assertJsonPath('data.connected', true)->assertJsonPath('data.provider', $provider->value);

        $account = RepositoryProviderAccount::query()->sole();
        $this->assertSame([$this->user->id, $provider], [$account->user_id, $account->provider]);
        $this->assertSame(FakeProviders::ACCESS_TOKEN, $account->access_token);
        // Never serialized, even by accident (toArray, toJson, logging a model).
        $this->assertSame([], array_intersect(['access_token', 'refresh_token'], array_keys($account->toArray())));
        $this->assertStringNotContainsString(FakeProviders::ACCESS_TOKEN, $account->toJson());
        $raw = DB::table('repository_provider_accounts')->first();
        foreach ([FakeProviders::ACCESS_TOKEN, FakeProviders::REFRESH_TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $raw->access_token.$raw->refresh_token, 'tokens are encrypted at rest');
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }
        $listing = $this->asUser($this->user)->getJson('/api/v1/repository-providers')->assertOk();
        $this->assertStringNotContainsString(FakeProviders::ACCESS_TOKEN, (string) $listing->getContent());
        $this->assertSame(['configured' => true, 'linked' => true], [
            'configured' => collect($listing->json('data'))->firstWhere('provider', $provider->value)['configured'],
            'linked' => collect($listing->json('data'))->firstWhere('provider', $provider->value)['account'] !== null,
        ]);
    }

    #[DataProvider('providers')]
    public function test_unknown_expired_and_replayed_states_are_refused(RepositoryProviderKey $provider): void
    {
        $this->complete($provider, str_repeat('a', 43))->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_STATE_INVALID');
        $this->asUser($this->user)->postJson("/api/v1/repository-providers/{$provider->value}/callback", ['code' => FakeProviders::GOOD_CODE])->assertUnprocessable();

        $state = $this->start($provider);
        $this->complete($provider, $state)->assertOk();
        $this->complete($provider, $state)->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_STATE_INVALID');

        $expired = $this->start($provider);
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->complete($provider, $expired)->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_STATE_INVALID');
        Carbon::setTestNow();
    }

    #[DataProvider('providers')]
    public function test_another_users_or_another_providers_state_is_refused_and_not_consumed(RepositoryProviderKey $provider): void
    {
        $state = $this->start($provider);
        $other = $provider === RepositoryProviderKey::GitLab ? RepositoryProviderKey::Bitbucket : RepositoryProviderKey::GitLab;
        $attacker = User::factory()->create();

        $this->complete($provider, $state, as: $attacker)->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_STATE_INVALID');
        $this->complete($other, $state)->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_STATE_INVALID');
        $this->assertSame(0, RepositoryProviderAccount::query()->count());
        $this->assertNull(RepositoryProviderOAuthState::query()->sole()->consumed_at);

        // The rightful user can still complete it.
        $this->complete($provider, $state)->assertOk();
    }

    #[DataProvider('providers')]
    public function test_a_refused_code_consumes_the_state_and_stores_nothing(RepositoryProviderKey $provider): void
    {
        $state = $this->start($provider);
        $this->complete($provider, $state, 'bad-code')->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_AUTH_REQUIRED');
        $this->complete($provider, $state)->assertUnprocessable();
        $this->assertSame(0, RepositoryProviderAccount::query()->count());
    }

    #[DataProvider('providers')]
    public function test_an_identity_linked_to_another_user_is_never_taken_over(RepositoryProviderKey $provider): void
    {
        $victim = User::factory()->create();
        ProviderFixtures::account($victim, $provider, 'victim-token');

        $this->complete($provider, $this->start($provider))->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_ACCOUNT_IN_USE');

        $this->assertSame([$victim->id], RepositoryProviderAccount::query()->pluck('user_id')->all());
        $this->assertSame('victim-token', RepositoryProviderAccount::query()->sole()->access_token);
        // GitLab supports revocation: the tokens just issued are revoked, never stored.
        $this->assertSame($provider === RepositoryProviderKey::GitLab ? 1 : 0, $this->providers->revocations);
    }

    public function test_the_callback_returns_to_a_project_only_while_the_user_may_connect_it(): void
    {
        $project = Project::factory()->for($this->user)->create();
        $state = $this->start(RepositoryProviderKey::GitLab, body: ['project_id' => $project->id]);
        $this->complete(RepositoryProviderKey::GitLab, $state)->assertOk()->assertJsonPath('data.project_id', $project->id);

        $foreign = Project::factory()->for(User::factory()->create())->create();
        $this->asUser($this->user)->postJson('/api/v1/repository-providers/gitlab/authorizations', ['project_id' => $foreign->id])->assertNotFound();
    }

    public function test_callback_and_start_accept_no_other_fields_and_need_a_known_provider(): void
    {
        $this->asUser($this->user)->postJson('/api/v1/repository-providers/gitlab/authorizations', ['redirect_uri' => 'https://evil.example'])->assertUnprocessable();
        $state = $this->start(RepositoryProviderKey::GitLab);
        $this->asUser($this->user)->postJson('/api/v1/repository-providers/gitlab/callback', ['state' => $state, 'code' => FakeProviders::GOOD_CODE, 'redirect_uri' => 'https://evil.example'])->assertUnprocessable();
        $this->asUser($this->user)->postJson('/api/v1/repository-providers/github/authorizations')->assertNotFound();
        $this->asUser($this->user)->postJson('/api/v1/repository-providers/bitbucket-server/authorizations')->assertNotFound();
    }

    #[DataProvider('providers')]
    public function test_an_unconfigured_provider_is_unavailable_and_never_contacted(RepositoryProviderKey $provider): void
    {
        config(["codedna.repository_providers.{$provider->value}.client_id" => '', "codedna.repository_providers.{$provider->value}.client_secret" => '']);

        $this->asUser($this->user)->postJson("/api/v1/repository-providers/{$provider->value}/authorizations")
            ->assertStatus(503)->assertJsonPath('error.code', 'PROVIDER_NOT_CONFIGURED');
        $this->assertFalse(collect($this->asUser($this->user)->getJson('/api/v1/repository-providers')->json('data'))->firstWhere('provider', $provider->value)['configured']);
        $this->assertSame([], $this->providers->requests);
    }

    public function test_disconnecting_revokes_where_supported_and_always_deletes_the_tokens(): void
    {
        ProviderFixtures::account($this->user, RepositoryProviderKey::GitLab);
        ProviderFixtures::account($this->user, RepositoryProviderKey::Bitbucket);

        $this->asUser($this->user)->deleteJson('/api/v1/repository-providers/gitlab')->assertOk()->assertJsonPath('data.revocation', 'REVOKED');
        $this->assertSame(1, $this->providers->revocations);
        $this->asUser($this->user)->deleteJson('/api/v1/repository-providers/bitbucket')->assertOk()->assertJsonPath('data.revocation', 'NOT_SUPPORTED');
        $this->assertSame(0, RepositoryProviderAccount::query()->count());
        $this->asUser($this->user)->deleteJson('/api/v1/repository-providers/gitlab')->assertOk()->assertJsonPath('data.revocation', 'NOT_LINKED');
    }

    public function test_tokens_are_deleted_even_when_remote_revocation_fails(): void
    {
        ProviderFixtures::account($this->user, RepositoryProviderKey::GitLab);
        $this->providers->override = fn ($request) => str_contains($request->url(), '/oauth/revoke') ? Http::response('', 500) : null;

        $this->asUser($this->user)->deleteJson('/api/v1/repository-providers/gitlab')->assertOk()->assertJsonPath('data.revocation', 'FAILED');
        $this->assertSame(0, RepositoryProviderAccount::query()->count());
    }

    public function test_a_users_accounts_are_their_own(): void
    {
        ProviderFixtures::account($this->user, RepositoryProviderKey::GitLab);
        $other = User::factory()->create();

        $this->asUser($other)->getJson('/api/v1/repository-providers')->assertOk()->assertJsonPath('data.0.account', null);
        $this->asUser($other)->deleteJson('/api/v1/repository-providers/gitlab')->assertOk()->assertJsonPath('data.revocation', 'NOT_LINKED');
        $this->assertSame(1, RepositoryProviderAccount::query()->count());
        $this->asUser($other)->getJson('/api/v1/repository-providers/gitlab/repositories')->assertStatus(409)->assertJsonPath('error.code', 'PROVIDER_AUTH_REQUIRED');
    }
}
