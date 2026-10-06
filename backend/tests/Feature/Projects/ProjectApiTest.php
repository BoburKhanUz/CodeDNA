<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/projects';

    private const RESOURCE_KEYS = [
        'id', 'type', 'name', 'slug', 'description', 'default_branch', 'source_type',
        'repository_url', 'language', 'status', 'created_at', 'updated_at',
    ];

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(User $user, array $overrides = []): TestResponse
    {
        return $this->asUser($user)->postJson(self::URL, array_merge([
            'name' => 'Billing Service',
            'slug' => 'billing-service',
            'source_type' => 'UPLOAD',
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $project = Project::factory()->create();

        foreach ([
            ['GET', self::URL], ['POST', self::URL], ['GET', self::URL."/{$project->id}"],
            ['PATCH', self::URL."/{$project->id}"], ['POST', self::URL."/{$project->id}/archive"],
        ] as [$method, $url]) {
            $this->fromBrowser()->json($method, $url, ['name' => 'x', 'slug' => 'x', 'source_type' => 'UPLOAD'])
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        }
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_creates_an_active_upload_project_owned_by_the_caller(): void
    {
        $user = User::factory()->create();

        $response = $this->create($user, [
            'description' => 'Invoices and payments.',
            'default_branch' => 'main',
            'language' => 'php',
        ]);

        $project = Project::query()->sole();
        $response->assertCreated()->assertExactJson(['data' => [
            'id' => $project->id,
            'type' => 'project',
            'name' => 'Billing Service',
            'slug' => 'billing-service',
            'description' => 'Invoices and payments.',
            'default_branch' => 'main',
            'source_type' => 'UPLOAD',
            'repository_url' => null,
            'language' => 'php',
            'status' => 'ACTIVE',
            'created_at' => $project->created_at?->toIso8601ZuluString(),
            'updated_at' => $project->updated_at?->toIso8601ZuluString(),
        ]]);
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame(ProjectStatus::Active, $project->status);
    }

    public function test_creates_a_repository_project_as_metadata_only(): void
    {
        $this->create(User::factory()->create(), [
            'source_type' => 'REPOSITORY',
            'repository_url' => 'https://git.example.test/acme/billing.git',
            'default_branch' => 'release/2026.10',
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'REPOSITORY')
            ->assertJsonPath('data.repository_url', 'https://git.example.test/acme/billing.git');
    }

    public function test_owner_status_and_internal_fields_cannot_be_set_on_create(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->create($user, [
            'id' => '01k6m2y5a7j1x9v3q8n4r2t6wz',
            'user_id' => $other->id,
            'status' => 'ARCHIVED',
            'metadata' => ['plan' => 'enterprise'],
        ])->assertCreated()->assertJsonPath('data.status', 'ACTIVE');

        $project = Project::query()->sole();
        $this->assertSame($user->id, $project->user_id);
        $this->assertNotSame('01k6m2y5a7j1x9v3q8n4r2t6wz', $project->id);
        $this->assertNull($project->metadata);
    }

    public function test_slugs_are_unique_per_user_but_shared_across_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->create($alice)->assertCreated();
        $this->create($alice, ['name' => 'Another'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.slug.0', 'You already have a project with this slug.');
        $this->create($bob)->assertCreated();
        $this->assertDatabaseCount('projects', 2);
    }

    public function test_slugs_are_normalized_to_lowercase(): void
    {
        $this->create(User::factory()->create(), ['slug' => 'Billing-Service'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'billing-service');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidProjects(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'missing slug' => [['slug' => ''], 'slug'],
            'slug with spaces' => [['slug' => 'billing service'], 'slug'],
            'slug double hyphen' => [['slug' => 'billing--service'], 'slug'],
            'slug leading hyphen' => [['slug' => '-billing'], 'slug'],
            'slug too long' => [['slug' => str_repeat('a', 101)], 'slug'],
            'unknown source type' => [['source_type' => 'GITHUB'], 'source_type'],
            'lowercase source type' => [['source_type' => 'upload'], 'source_type'],
            'upload with repository url' => [['repository_url' => 'https://git.example.test/a.git'], 'repository_url'],
            'repository without url' => [['source_type' => 'REPOSITORY'], 'repository_url'],
            'repository with http url' => [['source_type' => 'REPOSITORY', 'repository_url' => 'http://git.example.test/a.git'], 'repository_url'],
            'repository with ssh url' => [['source_type' => 'REPOSITORY', 'repository_url' => 'git@git.example.test:a/b.git'], 'repository_url'],
            'repository with credentials' => [['source_type' => 'REPOSITORY', 'repository_url' => 'https://user:token@git.example.test/a.git'], 'repository_url'],
            'repository with javascript url' => [['source_type' => 'REPOSITORY', 'repository_url' => 'javascript:alert(1)'], 'repository_url'],
            'branch with double dots' => [['default_branch' => 'feature/../main'], 'default_branch'],
            'branch with spaces' => [['default_branch' => 'my branch'], 'default_branch'],
            'branch ending in .lock' => [['default_branch' => 'main.lock'], 'default_branch'],
            'unknown language' => [['language' => 'Klingon'], 'language'],
            'description too long' => [['description' => str_repeat('a', 2001)], 'description'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidProjects')]
    public function test_rejects_invalid_projects(array $input, string $field): void
    {
        $response = $this->create(User::factory()->create(), $input);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_shows_only_the_owners_project(): void
    {
        $project = Project::factory()->create();
        $stranger = User::factory()->create();

        $this->asUser($project->user)->getJson(self::URL."/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
        $this->assertSame(self::RESOURCE_KEYS, array_keys($this->asUser($project->user)
            ->getJson(self::URL."/{$project->id}")->json('data')));

        // Another user's project looks exactly like a project that does not exist.
        $foreign = $this->asUser($stranger)->getJson(self::URL."/{$project->id}");
        $missing = $this->asUser($stranger)->getJson(self::URL.'/01k6m2y5a7j1x9v3q8n4r2t6wz');
        $foreign->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->assertSame(
            collect($missing->json('error'))->except('request_id')->all(),
            collect($foreign->json('error'))->except('request_id')->all(),
        );
        $this->asUser($stranger)->getJson(self::URL.'/not-a-ulid')->assertNotFound();
    }

    public function test_the_list_contains_only_the_callers_projects(): void
    {
        $user = User::factory()->create();
        $mine = Project::factory()->count(2)->for($user)->create();
        Project::factory()->count(3)->create();

        $response = $this->asUser($user)->getJson(self::URL)->assertOk();

        $this->assertEqualsCanonicalizing($mine->pluck('id')->all(), array_column($response->json('data'), 'id'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_the_list_is_paginated_with_a_stable_newest_first_order(): void
    {
        $user = User::factory()->create();
        $now = Carbon::parse('2026-10-07 12:00:00');
        $created = [];
        for ($i = 0; $i < 5; $i++) {
            // Two projects share each timestamp: the ID breaks the tie.
            $created[] = Project::factory()->for($user)->create(['created_at' => $now->copy()->subMinutes(intdiv($i, 2))]);
        }
        $expected = collect($created)->sortBy([['created_at', 'desc'], ['id', 'desc']])->pluck('id')->values()->all();

        $first = $this->asUser($user)->getJson(self::URL.'?per_page=2')->assertOk();
        $second = $this->asUser($user)->getJson(self::URL.'?per_page=2&page=2')->assertOk();
        $third = $this->asUser($user)->getJson(self::URL.'?per_page=2&page=3')->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($first->json()));
        $this->assertSame(['current_page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3], $first->json('meta'));
        $this->assertSame($expected, [
            ...array_column($first->json('data'), 'id'),
            ...array_column($second->json('data'), 'id'),
            ...array_column($third->json('data'), 'id'),
        ]);
    }

    public function test_pagination_parameters_are_validated_and_bounded(): void
    {
        $user = User::factory()->create();

        $this->asUser($user)->getJson(self::URL.'?per_page=101')
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['per_page']]]]);
        $this->asUser($user)->getJson(self::URL.'?page=0')
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['page']]]]);
        $this->asUser($user)->getJson(self::URL)
            ->assertOk()->assertJsonPath('meta.per_page', 25);
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        $active = Project::factory()->for($user)->create();
        Project::factory()->for($user)->archived()->create();

        $this->asUser($user)->getJson(self::URL.'?status=ACTIVE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $active->id);
        $this->asUser($user)->getJson(self::URL.'?status=DELETED')->assertUnprocessable();
    }

    public function test_updates_descriptive_fields(): void
    {
        $project = Project::factory()->create(['name' => 'Old', 'slug' => 'old']);

        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", [
            'name' => 'New Name',
            'slug' => 'new-name',
            'description' => 'Now with a description.',
            'language' => 'typescript',
            'default_branch' => 'develop',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'new-name')
            ->assertJsonPath('data.language', 'typescript');

        // Keeping its own slug is not a conflict.
        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", ['slug' => 'new-name'])
            ->assertOk();
    }

    public function test_update_rejects_status_source_type_and_ignores_internal_fields(): void
    {
        $project = Project::factory()->create();
        $other = User::factory()->create();

        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", ['status' => 'ARCHIVED'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.status.0', 'Use POST /api/v1/projects/{project}/archive to archive a project.');
        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", ['source_type' => 'REPOSITORY'])
            ->assertUnprocessable();
        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", ['repository_url' => 'https://git.example.test/a.git'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.repository_url.0', 'Upload projects do not have a repository URL.');
        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", [
            'user_id' => $other->id, 'id' => 'x', 'metadata' => ['a' => 1], 'created_at' => '2000-01-01T00:00:00Z',
        ])->assertOk();

        $fresh = $project->fresh();
        $this->assertSame($project->user_id, $fresh?->user_id);
        $this->assertSame(ProjectStatus::Active, $fresh?->status);
        $this->assertNull($fresh?->metadata);
        $this->assertSame($project->created_at?->toIso8601String(), $fresh?->created_at?->toIso8601String());
    }

    public function test_repository_projects_can_change_their_url(): void
    {
        $project = Project::factory()->fromRepository()->create();

        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", ['repository_url' => 'https://git.example.test/new.git'])
            ->assertOk()
            ->assertJsonPath('data.repository_url', 'https://git.example.test/new.git');
        $this->asUser($project->user)->patchJson(self::URL."/{$project->id}", ['repository_url' => null])
            ->assertUnprocessable();
    }

    public function test_a_user_cannot_update_or_archive_another_users_project(): void
    {
        $project = Project::factory()->create(['name' => 'Theirs']);
        $stranger = User::factory()->create();

        $this->asUser($stranger)->patchJson(self::URL."/{$project->id}", ['name' => 'Mine now'])
            ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        // Authorization runs before validation: invalid input still gets 404.
        $this->asUser($stranger)->patchJson(self::URL."/{$project->id}", ['slug' => '!!'])
            ->assertNotFound();
        $this->asUser($stranger)->postJson(self::URL."/{$project->id}/archive")
            ->assertNotFound();

        $this->assertSame('Theirs', $project->fresh()?->name);
        $this->assertSame(ProjectStatus::Active, $project->fresh()?->status);
    }

    public function test_archiving_is_explicit_idempotent_and_makes_the_project_read_only(): void
    {
        $project = Project::factory()->create();
        $owner = $project->user;

        $this->asUser($owner)->postJson(self::URL."/{$project->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');
        $this->asUser($owner)->postJson(self::URL."/{$project->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');

        $this->asUser($owner)->patchJson(self::URL."/{$project->id}", ['name' => 'Renamed'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->asUser($owner)->getJson(self::URL."/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');
    }

    public function test_projects_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();

        $this->asUser($project->user)->deleteJson(self::URL."/{$project->id}")
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $this->assertModelExists($project);
    }

    public function test_project_creation_and_updates_are_rate_limited_per_user(): void
    {
        $user = User::factory()->create();
        $createLimit = config('codedna.rate_limits.project_create_per_minute');
        for ($i = 0; $i < $createLimit; $i++) {
            $this->create($user, ['slug' => "project-{$i}"])->assertCreated();
        }
        $this->create($user, ['slug' => 'one-too-many'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED');

        $project = Project::query()->firstOrFail();
        $updateLimit = config('codedna.rate_limits.project_update_per_minute');
        for ($i = 0; $i < $updateLimit; $i++) {
            $this->asUser($user)->patchJson(self::URL."/{$project->id}", ['name' => "Name {$i}"])->assertOk();
        }
        $this->asUser($user)->patchJson(self::URL."/{$project->id}", ['name' => 'Too many'])
            ->assertTooManyRequests();
        // Reading is not limited by the write limiters.
        $this->asUser($user)->getJson(self::URL)->assertOk();
    }
}
