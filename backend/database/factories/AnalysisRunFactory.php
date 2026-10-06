<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnalysisRunStatus;
use App\Models\AnalysisRun;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AnalysisRun>
 */
class AnalysisRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_snapshot_id' => SourceSnapshot::factory(),
            // Always the snapshot's own project (also enforced by a composite foreign key).
            'project_id' => fn (array $attributes): string => SourceSnapshot::query()
                ->whereKey($attributes['source_snapshot_id'])
                ->value('project_id'),
            'status' => AnalysisRunStatus::Queued,
            'metadata' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => AnalysisRunStatus::Running,
            'started_at' => Carbon::now()->subMinute(),
        ]);
    }

    /** Fixture versions and hash; not produced by a real analyzer. */
    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => AnalysisRunStatus::Succeeded,
            'analyzer_version' => '0.1.0',
            'ir_version' => '1.0',
            'metrics_version' => '1.0',
            'scoring_version' => '1.0',
            'contract_version' => '1.0',
            'started_at' => Carbon::now()->subMinutes(2),
            'completed_at' => Carbon::now()->subMinute(),
            'result_hash' => hash('sha256', 'fixture-result-'.fake()->uuid()),
        ]);
    }

    public function failed(string $code = 'SOURCE_TOO_LARGE'): static
    {
        return $this->state(fn (): array => [
            'status' => AnalysisRunStatus::Failed,
            'started_at' => Carbon::now()->subMinutes(2),
            'completed_at' => Carbon::now()->subMinute(),
            'failed_at' => Carbon::now()->subMinute(),
            'failure_code' => $code,
            'failure_message' => 'Archive exceeds the maximum allowed size.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => AnalysisRunStatus::Cancelled,
            'completed_at' => Carbon::now(),
        ]);
    }
}
