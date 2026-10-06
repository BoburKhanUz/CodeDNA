<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DnaSnapshotStatus;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * TEST FIXTURE ONLY. The scores below are not produced by the CodeDNA
 * engine (Phase 11) and must never be presented as real results; every
 * snapshot made here is marked with evidence.fixture = true.
 *
 * @extends Factory<DnaSnapshot>
 */
class DnaSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_run_id' => AnalysisRun::factory()->succeeded(),
            'source_snapshot_id' => fn (array $attributes): string => (string) AnalysisRun::query()
                ->whereKey($attributes['analysis_run_id'])->value('source_snapshot_id'),
            'project_id' => fn (array $attributes): string => (string) AnalysisRun::query()
                ->whereKey($attributes['analysis_run_id'])->value('project_id'),
            'user_id' => fn (array $attributes): string => (string) Project::query()
                ->whereKey($attributes['project_id'])->value('user_id'),
            'analyzer_version' => '0.1.0',
            'ir_version' => '1.0',
            'metrics_version' => '1.0',
            'scoring_version' => '1.0',
            'contract_version' => '1.0',
            'status' => DnaSnapshotStatus::Ready,
            'overall_score' => '0.5000',
            'dimensions' => [
                'readability' => ['status' => 'scored', 'score' => '0.5000'],
                'architecture' => ['status' => 'not_assessed', 'score' => null],
            ],
            'competencies' => null,
            'strengths' => [],
            'weaknesses' => [],
            'evidence' => ['fixture' => true],
            'result_hash' => fn (array $attributes): string => (string) AnalysisRun::query()
                ->whereKey($attributes['analysis_run_id'])->value('result_hash'),
        ];
    }

    public function insufficientData(): static
    {
        return $this->state(fn (): array => [
            'status' => DnaSnapshotStatus::InsufficientData,
            'overall_score' => null,
        ]);
    }
}
