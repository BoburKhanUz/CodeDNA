<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\ProjectStatus;
use App\Enums\SourceType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_belongs_to_a_user_and_a_user_has_many_projects(): void
    {
        $user = User::factory()->create();
        $first = $user->projects()->create(['name' => 'Billing Service', 'slug' => 'billing-service', 'source_type' => SourceType::Upload]);
        $second = $user->projects()->create(['name' => 'Web App', 'slug' => 'web-app', 'source_type' => SourceType::Upload]);

        $this->assertTrue($first->user->is($user));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $user->projects()->pluck('id')->all());
    }

    public function test_new_projects_are_active_and_use_ulids(): void
    {
        $project = User::factory()->create()->projects()->create([
            'name' => 'Billing Service', 'slug' => 'billing-service', 'source_type' => SourceType::Upload,
        ])->refresh();

        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertTrue(Str::isUlid($project->id));
        $this->assertNotNull($project->created_at);
        $this->assertNotNull($project->updated_at);
    }

    public function test_slugs_are_unique_per_user(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user)->create(['slug' => 'api']);

        $this->expectException(UniqueConstraintViolationException::class);
        Project::factory()->for($user)->create(['slug' => 'api']);
    }

    public function test_different_users_may_use_the_same_slug(): void
    {
        Project::factory()->create(['slug' => 'api']);
        Project::factory()->create(['slug' => 'api']);

        $this->assertSame(2, Project::query()->where('slug', 'api')->count());
    }

    public function test_owner_status_and_metadata_are_not_mass_assignable(): void
    {
        // Strict mode (Phase 03) turns silently discarded attributes into an error.
        foreach (['user_id' => 'someone-else', 'status' => 'ARCHIVED', 'metadata' => ['a' => 1]] as $field => $value) {
            try {
                new Project(['name' => 'X', 'slug' => 'x', 'source_type' => 'UPLOAD', $field => $value]);
                $this->fail("{$field} must not be mass assignable.");
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(ProjectStatus::Active, (new Project(['name' => 'X']))->status);
    }

    public function test_archiving_changes_the_status(): void
    {
        $project = Project::factory()->create();

        $project->archive();

        $this->assertSame(ProjectStatus::Archived, $project->refresh()->status);
        $this->assertFalse($project->isActive());
    }

    public function test_repository_projects_require_an_https_url_and_upload_projects_have_none(): void
    {
        $repository = Project::factory()->fromRepository()->create();
        $this->assertSame(SourceType::Repository, $repository->source_type);

        foreach ([
            ['source_type' => 'REPOSITORY', 'repository_url' => null],
            ['source_type' => 'UPLOAD', 'repository_url' => 'https://git.example.test/a.git'],
            ['source_type' => 'REPOSITORY', 'repository_url' => 'file:///etc/passwd'],
            ['source_type' => 'REPOSITORY', 'repository_url' => 'git@git.example.test:a/b.git'],
        ] as $invalid) {
            $this->assertRejectedByDatabase(fn () => Project::factory()->create($invalid));
        }
    }

    public function test_required_fields_and_formats_are_enforced(): void
    {
        foreach ([
            ['name' => '   '],
            ['slug' => 'Not A Slug'],
            ['slug' => '-leading-dash'],
            ['language' => 'PHP'],
        ] as $invalid) {
            $this->assertRejectedByDatabase(fn () => Project::factory()->create($invalid));
        }

        // The database rejects unknown states even when the application layer is bypassed.
        $this->assertRejectedByDatabase(fn () => DB::table('projects')->where('id', Project::factory()->create()->id)->update(['status' => 'DELETED']));
        $this->assertRejectedByDatabase(fn () => DB::table('projects')->insert([
            'id' => (string) Str::ulid(), 'user_id' => User::factory()->create()->id,
            'name' => null, 'slug' => 'x', 'source_type' => 'UPLOAD',
        ]));
    }

    public function test_metadata_is_stored_as_a_jsonb_object(): void
    {
        $project = Project::factory()->create();
        $project->metadata = ['import' => ['format' => 'zip', 'files' => 12]];
        $project->save();

        // assertEquals: jsonb normalizes object key order.
        $this->assertEquals(['import' => ['format' => 'zip', 'files' => 12]], $project->refresh()->metadata);
        $this->assertSame('jsonb', DB::scalar('select pg_typeof(metadata)::text from projects where id = ?', [$project->id]));
        $this->assertSame(1, Project::query()->where('metadata->import->format', 'zip')->count());

        $this->assertRejectedByDatabase(fn () => DB::table('projects')->where('id', $project->id)->update(['metadata' => '[1, 2]']));
        $this->assertRejectedByDatabase(fn () => DB::table('projects')->where('id', $project->id)
            ->update(['metadata' => json_encode(['blob' => str_repeat('x', 20_000)])]));
    }

    private function assertRejectedByDatabase(callable $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the operation.');
    }
}
