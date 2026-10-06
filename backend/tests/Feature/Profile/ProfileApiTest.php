<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\DeveloperProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/profile';

    private const RESOURCE_KEYS = [
        'id', 'type', 'display_name', 'bio', 'avatar_url', 'timezone', 'locale', 'country_code', 'city',
        'job_title', 'company', 'website_url', 'github_username', 'linkedin_url', 'preferred_language', 'updated_at',
    ];

    private function developer(): User
    {
        return User::factory()->has(DeveloperProfile::factory())->create();
    }

    public function test_requires_authentication(): void
    {
        foreach (['GET', 'PATCH'] as $method) {
            $this->fromBrowser()->json($method, self::URL, ['display_name' => 'Ada'])
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        }
    }

    public function test_returns_the_authenticated_developers_profile(): void
    {
        $user = User::factory()->has(DeveloperProfile::factory()->complete())->create();
        $profile = $user->developerProfile()->sole();

        $this->actingAs($user, 'web')->fromBrowser()->getJson(self::URL)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $profile->id,
                'type' => 'developer_profile',
                'display_name' => 'Ada L.',
                'bio' => 'Backend developer who enjoys clean data models.',
                'avatar_url' => 'https://images.example.test/avatars/ada.png',
                'timezone' => 'Asia/Tashkent',
                'locale' => 'uz',
                'country_code' => 'UZ',
                'city' => 'Tashkent',
                'job_title' => 'Senior Engineer',
                'company' => 'Example Corp',
                'website_url' => 'https://ada.example.test',
                'github_username' => 'ada-lovelace',
                'linkedin_url' => 'https://www.linkedin.com/in/ada-example',
                'preferred_language' => 'php',
                'updated_at' => $profile->updated_at?->toIso8601ZuluString(),
            ]]);
    }

    public function test_a_user_without_a_profile_gets_the_default_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromBrowser()->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.display_name', null);
        $this->actingAs($user, 'web')->fromBrowser()->getJson(self::URL)->assertOk();
        $this->assertSame(1, DeveloperProfile::query()->where('user_id', $user->id)->count());
    }

    public function test_updates_the_profile(): void
    {
        $user = $this->developer();

        $response = $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, [
            'display_name' => 'Ada',
            'bio' => 'Writes compilers.',
            'avatar_url' => 'https://images.example.test/a.png',
            'timezone' => 'Europe/Moscow',
            'locale' => 'ru',
            'country_code' => 'uz',
            'city' => 'Tashkent',
            'job_title' => 'Engineer',
            'company' => 'Example',
            'website_url' => 'https://ada.example.test/about?x=1',
            'github_username' => '@octo-cat',
            'linkedin_url' => 'https://www.linkedin.com/in/ada',
            'preferred_language' => 'typescript',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.display_name', 'Ada')
            ->assertJsonPath('data.timezone', 'Europe/Moscow')
            ->assertJsonPath('data.locale', 'ru')
            ->assertJsonPath('data.country_code', 'UZ')
            ->assertJsonPath('data.github_username', 'octo-cat')
            ->assertJsonPath('data.preferred_language', 'typescript');
        $this->assertDatabaseHas('developer_profiles', [
            'user_id' => $user->id,
            'display_name' => 'Ada',
            'timezone' => 'Europe/Moscow',
            'country_code' => 'UZ',
            'github_username' => 'octo-cat',
        ]);
    }

    public function test_patch_is_partial_and_null_or_empty_clears_optional_fields(): void
    {
        $user = User::factory()->has(DeveloperProfile::factory()->complete())->create();

        $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, ['city' => null, 'company' => ''])
            ->assertOk()
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.job_title', 'Senior Engineer')
            ->assertJsonPath('data.timezone', 'Asia/Tashkent');
    }

    public function test_timezone_and_locale_cannot_be_cleared(): void
    {
        $user = $this->developer();

        $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, ['timezone' => null, 'locale' => ''])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['timezone', 'locale']]]]);
    }

    public function test_a_developer_cannot_change_another_users_profile_or_internal_fields(): void
    {
        $alice = $this->developer();
        $bob = User::factory()->has(DeveloperProfile::factory()->complete())->create();
        $bobsProfile = $bob->developerProfile()->sole();
        $alicesProfile = $alice->developerProfile()->sole();

        $this->actingAs($alice, 'web')->fromBrowser()->patchJson(self::URL, [
            'id' => $bobsProfile->id,
            'user_id' => $bob->id,
            'metadata' => ['role' => 'admin'],
            'display_name' => 'Alice',
        ])->assertOk()->assertJsonPath('data.id', $alicesProfile->id);

        $this->assertSame($alice->id, $alicesProfile->refresh()->user_id);
        $this->assertNull($alicesProfile->metadata);
        $this->assertSame('Alice', $alicesProfile->display_name);
        $this->assertSame('Ada L.', $bobsProfile->refresh()->display_name);
        $this->assertSame($bob->id, $bobsProfile->user_id);
    }

    public function test_the_policy_allows_only_the_owner(): void
    {
        $alice = $this->developer();
        $bob = $this->developer();
        $bobsProfile = $bob->developerProfile()->sole();

        $this->assertFalse(Gate::forUser($alice)->allows('view', $bobsProfile));
        $this->assertFalse(Gate::forUser($alice)->allows('update', $bobsProfile));
        $this->assertTrue(Gate::forUser($bob)->allows('view', $bobsProfile));
        $this->assertTrue(Gate::forUser($bob)->allows('update', $bobsProfile));
    }

    public function test_the_response_contains_only_allowed_fields_and_no_secrets(): void
    {
        $user = $this->developer();
        $profile = $user->developerProfile()->sole();
        $profile->forceFill(['metadata' => ['internal_note' => 'not-for-the-browser']])->save();

        $response = $this->actingAs($user, 'web')->fromBrowser()->getJson(self::URL)->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(self::RESOURCE_KEYS, array_keys($response->json('data')));
        $body = $response->getContent() ?: '';
        foreach (['not-for-the-browser', 'metadata', 'user_id', $user->password, (string) $user->remember_token, 'password', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidInput(): array
    {
        return [
            'timezone offset' => [['timezone' => 'UTC+5'], 'timezone'],
            'timezone unknown' => [['timezone' => 'Mars/Phobos'], 'timezone'],
            'timezone wrong case' => [['timezone' => 'asia/tashkent'], 'timezone'],
            'timezone not a string' => [['timezone' => 5], 'timezone'],
            'locale unsupported' => [['locale' => 'fr'], 'locale'],
            'locale wrong case' => [['locale' => 'EN'], 'locale'],
            'avatar http' => [['avatar_url' => 'http://images.example.test/a.png'], 'avatar_url'],
            'avatar javascript' => [['avatar_url' => 'javascript:alert(1)'], 'avatar_url'],
            'website data' => [['website_url' => 'data:text/html,<script>alert(1)</script>'], 'website_url'],
            'website credentials' => [['website_url' => 'https://user:secret@example.test'], 'website_url'],
            'website relative' => [['website_url' => '/admin'], 'website_url'],
            'website no host' => [['website_url' => 'https://'], 'website_url'],
            'website whitespace' => [['website_url' => 'https://example.test/a b'], 'website_url'],
            'website too long' => [['website_url' => 'https://example.test/'.str_repeat('a', 2048)], 'website_url'],
            'linkedin not a url' => [['linkedin_url' => 'linkedin.com/in/ada'], 'linkedin_url'],
            'github leading hyphen' => [['github_username' => '-ada'], 'github_username'],
            'github trailing hyphen' => [['github_username' => 'ada-'], 'github_username'],
            'github double hyphen' => [['github_username' => 'ada--l'], 'github_username'],
            'github too long' => [['github_username' => str_repeat('a', 40)], 'github_username'],
            'github space' => [['github_username' => 'ada l'], 'github_username'],
            'github url' => [['github_username' => 'https://github.com/ada'], 'github_username'],
            'country three letters' => [['country_code' => 'UZB'], 'country_code'],
            'country digits' => [['country_code' => '1A'], 'country_code'],
            'display name too long' => [['display_name' => str_repeat('a', 101)], 'display_name'],
            'bio too long' => [['bio' => str_repeat('a', 1001)], 'bio'],
            'city too long' => [['city' => str_repeat('a', 101)], 'city'],
            'job title too long' => [['job_title' => str_repeat('a', 101)], 'job_title'],
            'company too long' => [['company' => str_repeat('a', 101)], 'company'],
            'preferred language unknown' => [['preferred_language' => 'cobol-2099'], 'preferred_language'],
            'display name array' => [['display_name' => ['a']], 'display_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidInput')]
    public function test_rejects_invalid_input(array $input, string $field): void
    {
        $user = $this->developer();
        $before = $user->developerProfile()->sole()->getAttributes();

        $response = $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, $input);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        $this->assertSame([$field], array_keys($response->json('error.details.fields')));
        $this->assertSame($before, $user->developerProfile()->sole()->getAttributes());
    }

    public function test_validation_messages_are_specific(): void
    {
        $user = $this->developer();

        $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, [
            'timezone' => 'UTC+5',
            'website_url' => 'javascript:alert(1)',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.timezone.0', 'The timezone must be a valid IANA time zone, such as Asia/Tashkent.')
            ->assertJsonPath('error.details.fields.website_url.0', 'The website url must be a valid https:// URL.');
    }

    public function test_accepts_canonical_time_zones(): void
    {
        $user = $this->developer();

        foreach (['UTC', 'Asia/Tashkent', 'Europe/Moscow', 'America/Argentina/Buenos_Aires'] as $timezone) {
            $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, ['timezone' => $timezone])
                ->assertOk()
                ->assertJsonPath('data.timezone', $timezone);
        }
    }

    public function test_profile_updates_are_rate_limited_per_user(): void
    {
        $user = $this->developer();
        $limit = config('codedna.rate_limits.profile_update_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, ['city' => "City {$i}"])->assertOk();
        }

        $this->actingAs($user, 'web')->fromBrowser()->patchJson(self::URL, ['city' => 'Too many'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
        $this->assertSame('City '.($limit - 1), $user->developerProfile()->sole()->city);
        // Reading is not limited by the update limiter.
        $this->actingAs($user, 'web')->fromBrowser()->getJson(self::URL)->assertOk();
    }
}
