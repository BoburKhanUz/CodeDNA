<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Enums\SourceType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'default_branch' => null,
            'source_type' => SourceType::Upload,
            'repository_url' => null,
            'language' => 'php',
            'status' => ProjectStatus::Active,
            'metadata' => null,
        ];
    }

    /** A project whose source comes from a repository URL (no provider integration yet). */
    public function fromRepository(string $url = 'https://git.example.test/acme/service.git'): static
    {
        return $this->state(fn (): array => [
            'source_type' => SourceType::Repository,
            'repository_url' => $url,
            'default_branch' => 'main',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ProjectStatus::Archived]);
    }
}
