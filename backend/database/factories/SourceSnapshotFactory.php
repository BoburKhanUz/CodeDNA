<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\Project;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test data only: references a storage object that does not exist and
 * contains no source code.
 *
 * @extends Factory<SourceSnapshot>
 */
class SourceSnapshotFactory extends Factory
{
    /**
     * Next version per project. `count(n)` makes every model before saving
     * any, so the database maximum alone would repeat the same version.
     *
     * @var array<string, int>
     */
    private static array $nextVersions = [];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => function (array $attributes): int {
                $projectId = (string) $attributes['project_id'];
                $stored = (int) SourceSnapshot::query()->where('project_id', $projectId)->max('version');

                return self::$nextVersions[$projectId] = max(self::$nextVersions[$projectId] ?? 0, $stored) + 1;
            },
            'source_type' => SourceType::Upload,
            'storage_disk' => 'sources',
            'storage_key' => 'snapshots/'.Str::lower((string) Str::ulid()).'.zip',
            'source_hash' => hash('sha256', Str::random(32)),
            'size_bytes' => fake()->numberBetween(1_024, 5_000_000),
            'file_count' => fake()->numberBetween(1, 500),
            'primary_language' => 'php',
            'metadata' => ['archive_format' => 'zip'],
        ];
    }
}
