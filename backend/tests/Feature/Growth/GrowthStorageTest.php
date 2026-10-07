<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Exceptions\DomainRuleViolation;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ChallengeFixtures;
use Tests\TestCase;

/**
 * The growth tables: immutability (model and trigger), lineage, uniqueness
 * per rules version, and the constraints that keep stored growth honest.
 */
final class GrowthStorageTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private SkillGapSnapshot $first;

    private SkillGapSnapshot $second;

    private GrowthSnapshot $growth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create();
        $this->first = ChallengeFixtures::manyGaps($this->project);
        $this->travel(1)->hours();
        $this->second = ChallengeFixtures::oneGap($this->project);
        $this->growth = app(CalculateGrowthSnapshot::class)->handle($this->second->id)->snapshot;
    }

    private function assertRefused(Closure $statement, string $constraint): void
    {
        try {
            DB::transaction(function () use ($statement) {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $statement();
            });
            $this->fail("Expected the database to refuse ({$constraint}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function snapshotRow(array $changes = []): array
    {
        $row = (array) DB::table('growth_snapshots')->where('id', $this->growth->id)->first();

        return ['id' => strtolower((string) Str::ulid())] + $changes + $row;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function observationRow(array $changes = []): array
    {
        $row = (array) DB::table('growth_observations')->where('growth_snapshot_id', $this->growth->id)->where('metric_key', 'OVERALL')->first();

        return ['id' => strtolower((string) Str::ulid())] + $changes + $row;
    }

    public function test_growth_snapshots_and_observations_never_change(): void
    {
        foreach (['status' => 'NOT_ESTABLISHED', 'summary' => '{}', 'rules_version' => '1.0.1', 'previous_skill_gap_snapshot_id' => null, 'assessed_at' => '2020-01-01 00:00:00'] as $column => $value) {
            $this->assertRefused(fn () => DB::table('growth_snapshots')->where('id', $this->growth->id)->update([$column => $value]), 'growth history is immutable');
        }
        foreach (['status' => 'REGRESSED', 'delta' => '0.9000', 'current_value' => '1.0000', 'level_change' => 'DOWN'] as $column => $value) {
            $this->assertRefused(fn () => DB::table('growth_observations')->where('growth_snapshot_id', $this->growth->id)->update([$column => $value]), 'growth history is immutable');
        }
    }

    public function test_models_refuse_updates_and_deletes(): void
    {
        $observation = GrowthObservation::query()->where('growth_snapshot_id', $this->growth->id)->firstOrFail();
        $attempts = [
            fn () => $this->growth->forceFill(['status' => 'NOT_ESTABLISHED'])->save(),
            fn () => $this->growth->delete(),
            fn () => $observation->forceFill(['status' => 'REGRESSED'])->save(),
            fn () => $observation->delete(),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} was allowed");
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('COMPARED', GrowthSnapshot::query()->findOrFail($this->growth->id)->status->value);
    }

    public function test_one_growth_snapshot_per_assessment_and_rules_version(): void
    {
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow()), 'growth_snapshots_assessment_rules_unique');

        // A new rules version is a new snapshot; the old one stays as it was.
        DB::table('growth_snapshots')->insert($this->snapshotRow(['rules_version' => '1.1.0', 'rules_fingerprint' => str_repeat('b', 64)]));
        $this->assertSame(2, GrowthSnapshot::query()->where('skill_gap_snapshot_id', $this->second->id)->count());
    }

    public function test_lineage_is_the_assessments_own(): void
    {
        $other = ChallengeFixtures::oneGap(Project::factory()->create());

        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow(['rules_version' => '1.1.0', 'dna_snapshot_id' => $this->first->dna_snapshot_id])), 'growth_snapshots_lineage_foreign');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow(['rules_version' => '1.1.0', 'user_id' => $other->user_id])), 'growth_snapshots_lineage_foreign');
        // The baseline must be an assessment of the same project.
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow(['rules_version' => '1.1.0',
            'previous_skill_gap_snapshot_id' => $other->id, 'previous_competency_snapshot_id' => $other->competency_snapshot_id, 'previous_dna_snapshot_id' => $other->dna_snapshot_id,
            'previous_analysis_run_id' => $other->analysis_run_id, 'previous_source_snapshot_id' => $other->source_snapshot_id])), 'growth_snapshots_previous_lineage_foreign');
        // Assessments with growth cannot be deleted.
        $this->assertRefused(fn () => DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $this->first->id)->delete() + DB::table('skill_gap_snapshots')->where('id', $this->first->id)->delete(), 'growth_snapshots_previous_lineage_foreign');
    }

    public function test_snapshot_constraints(): void
    {
        $v = ['rules_version' => '1.1.0'];
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow($v + ['status' => 'IMPROVED'])), 'growth_snapshots_status_valid');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow($v + ['status' => 'NOT_ESTABLISHED'])), 'growth_snapshots_baseline_iff_established');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow($v + ['previous_dna_snapshot_id' => null])), 'growth_snapshots_baseline_all_or_none');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow($v + ['previous_assessed_at' => $this->growth->assessed_at->copy()->addMinute()])), 'growth_snapshots_baseline_earlier');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow($v + ['differences' => '["metrics_version"]'])), 'growth_snapshots_differences_iff_incomparable');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow($v + ['status' => 'INCOMPARABLE'])), 'growth_snapshots_differences_iff_incomparable');
        $this->assertRefused(fn () => DB::table('growth_snapshots')->insert($this->snapshotRow(['rules_version' => 'latest'])), 'growth_snapshots_rules');
    }

    public function test_observation_constraints(): void
    {
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow()), 'growth_observations_metric_unique');
        $new = ['metric_key' => 'NEW', 'position' => 999];
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['delta' => '0.4000'])), 'growth_observations_delta_formula');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['better' => 'LOWER'])), 'growth_observations_direction');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['metric_type' => 'SKILL_GAP'])), 'growth_observations_direction');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['metric_type' => 'AGGREGATE'])), 'growth_observations_type_valid');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['status' => 'NOT_ESTABLISHED'])), 'growth_observations_status_valid');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['previous_value' => null])), 'growth_observations_values_all_or_none');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['previous_value' => null, 'current_value' => null, 'delta' => null])), 'growth_observations_classified_needs_delta');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['previous_value' => '-0.5000', 'delta' => '1.3050'])), 'growth_observations_values_range');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['level_change' => 'SIDEWAYS'])), 'growth_observations_level_change');
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['project_id' => Project::factory()->create()->id])), 'growth_observations_snapshot_foreign');

        // A NOT_ESTABLISHED snapshot has no observations.
        $baseline = app(CalculateGrowthSnapshot::class)->handle($this->first->id)->snapshot;
        $this->assertSame('NOT_ESTABLISHED', $baseline->status->value);
        $this->assertRefused(fn () => DB::table('growth_observations')->insert($this->observationRow($new + ['growth_snapshot_id' => $baseline->id])), 'only a COMPARED growth snapshot has observations');
    }

    public function test_no_source_code_or_analyzer_payload_is_stored(): void
    {
        $columns = array_merge(
            DB::getSchemaBuilder()->getColumnListing('growth_snapshots'),
            DB::getSchemaBuilder()->getColumnListing('growth_observations'),
        );
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/source$|code|payload|result$|storage|path|url|ai_/', $column);
        }
    }
}
