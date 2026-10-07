<?php

declare(strict_types=1);

namespace Tests\Feature\Roadmap;

use App\Actions\Roadmap\CompleteRoadmapStep;
use App\Actions\Roadmap\GenerateRoadmap;
use App\Enums\Roadmap\RoadmapStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\RoadmapStep;
use App\Models\RoadmapStepCompletion;
use App\Models\User;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeSelector;
use App\Services\Roadmap\DevelopmentFocusResolver;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapGenerator;
use App\Services\Roadmap\RoadmapRules;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ChallengeFixtures;
use Tests\TestCase;

/**
 * The roadmap tables: immutable content (model and trigger), one-way
 * status, lineage, uniqueness and reproducibility of stored roadmaps.
 */
final class RoadmapStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private RoadmapSnapshot $roadmap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        ChallengeFixtures::oneGap($this->project);
        $this->roadmap = app(GenerateRoadmap::class)->handle($this->project, $this->owner)->roadmap;
    }

    private function assertRefused(Closure $statement, string $constraint): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Expected the database to refuse ({$constraint}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, string $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    public function test_content_and_provenance_never_change(): void
    {
        foreach (['roadmap_fingerprint' => str_repeat('a', 64), 'focus' => '{}', 'tracks' => '[{"key":"X"}]', 'skill_gap_snapshot_id' => $this->roadmap->id,
            'rules_version' => '1.0.1', 'step_count' => 99, 'created_at' => '2020-01-01 00:00:00'] as $column => $value) {
            $this->assertRefused(fn () => DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)->update([$column => $value]), 'immutable');
        }

        $this->expectException(DomainRuleViolation::class);
        $this->roadmap->forceFill(['roadmap_fingerprint' => str_repeat('a', 64)])->save();
    }

    public function test_a_final_roadmap_never_changes_again(): void
    {
        DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)->update(['status' => 'COMPLETED', 'completed_at' => Carbon::now()]);

        $this->assertRefused(fn () => DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)->update(['status' => 'ACTIVE', 'completed_at' => null]), 'is final');
        $this->expectException(DomainRuleViolation::class);
        RoadmapSnapshot::query()->findOrFail($this->roadmap->id)->forceFill(['status' => RoadmapStatus::Active, 'completed_at' => null])->save();
    }

    public function test_status_fields_are_consistent(): void
    {
        $this->assertRefused(fn () => DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)->update(['status' => 'COMPLETED']), 'roadmap_snapshots_completed_iff');
        $this->assertRefused(fn () => DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)->update(['status' => 'SUPERSEDED', 'superseded_at' => Carbon::now()]), 'roadmap_snapshots_superseded_iff');
        $this->assertRefused(fn () => DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)->update(['status' => 'DONE']), 'roadmap_snapshots_status_valid');
        // A successor must be a roadmap of the same project (checked at commit; immediately here, inside the test transaction).
        $this->assertRefused(function (): void {
            DB::statement('SET CONSTRAINTS roadmap_snapshots_successor_foreign IMMEDIATE');
            DB::table('roadmap_snapshots')->where('id', $this->roadmap->id)
                ->update(['status' => 'SUPERSEDED', 'superseded_at' => Carbon::now(), 'superseded_by_id' => strtolower((string) Str::ulid())]);
        }, 'roadmap_snapshots_successor_foreign');
    }

    public function test_one_roadmap_per_snapshot_and_versions_and_one_active_per_project(): void
    {
        $copy = ['id' => strtolower((string) Str::ulid())] + $this->row('roadmap_snapshots', $this->roadmap->id);
        $this->assertRefused(fn () => DB::table('roadmap_snapshots')->insert($copy), 'roadmap_snapshots_identity_unique');

        $copy['rules_version'] = '1.0.1';
        $this->assertRefused(fn () => DB::table('roadmap_snapshots')->insert($copy), 'roadmap_snapshots_one_active_unique');

        DB::table('roadmap_snapshots')->insert(['status' => 'COMPLETED', 'completed_at' => Carbon::now()] + $copy);
        $this->assertSame(2, RoadmapSnapshot::query()->count(), 'final roadmaps do not count as active');
    }

    public function test_the_lineage_must_match_the_skill_gap_snapshot(): void
    {
        $other = ChallengeFixtures::manyGaps(Project::factory()->for(User::factory())->create());
        $mine = $this->row('roadmap_snapshots', $this->roadmap->id);

        foreach (['project_id', 'user_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id'] as $column) {
            $copy = ['id' => strtolower((string) Str::ulid()), 'status' => 'COMPLETED', 'completed_at' => Carbon::now(), 'rules_version' => '1.0.'.random_int(1, 999),
                'skill_gap_snapshot_id' => $other->id, $column => $mine[$column]] + $this->row('roadmap_snapshots', $this->roadmap->id);
            foreach (['project_id', 'user_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id'] as $lineage) {
                if ($lineage !== $column) {
                    $copy[$lineage] = $other->{$lineage};
                }
            }
            $this->assertRefused(fn () => DB::table('roadmap_snapshots')->insert($copy), 'roadmap_snapshots_lineage_foreign');
        }
    }

    public function test_steps_and_completions_are_tied_to_their_roadmap(): void
    {
        $step = RoadmapStep::query()->where('roadmap_snapshot_id', $this->roadmap->id)->orderBy('position')->firstOrFail();
        $copy = ['id' => strtolower((string) Str::ulid())] + $this->row('roadmap_steps', $step->id);

        $this->assertRefused(fn () => DB::table('roadmap_steps')->insert($copy), 'roadmap_steps_key_unique');
        $this->assertRefused(fn () => DB::table('roadmap_steps')->insert(['step_key' => 'ch-other', 'position' => 50, 'step_position' => 9,
            'user_id' => User::factory()->create()->id] + $copy), 'roadmap_steps_snapshot_foreign');
        $this->assertRefused(fn () => DB::table('roadmap_steps')->insert(['step_key' => 'ch-other', 'position' => 50, 'step_position' => 9,
            'challenge_key' => 'CODE_HYGIENE_001', 'challenge_version' => '1.0.0', 'challenge_title' => 'x', 'challenge_difficulty' => 'BEGINNER'] + $copy), 'roadmap_steps_challenge_reference');
        $this->assertRefused(fn () => DB::table('roadmap_steps')->insert(['step_key' => 'ch-other', 'position' => 50, 'step_position' => 9, 'type' => 'COURSE'] + $copy), 'roadmap_steps_type_valid');
        $this->assertRefused(fn () => DB::table('roadmap_steps')->insert(['step_key' => '../../x', 'position' => 50, 'step_position' => 9] + $copy), 'roadmap_steps_key_format');

        $completion = ['id' => strtolower((string) Str::ulid()), 'roadmap_snapshot_id' => $this->roadmap->id, 'roadmap_step_id' => $step->id,
            'project_id' => $this->project->id, 'user_id' => $this->owner->id, 'completed_at' => Carbon::now()];
        DB::table('roadmap_step_completions')->insert($completion);
        $this->assertRefused(fn () => DB::table('roadmap_step_completions')->insert(['id' => strtolower((string) Str::ulid())] + $completion), 'roadmap_step_completions_step_unique');

        // A step of another roadmap cannot be completed through this one.
        $otherProject = Project::factory()->for($this->owner)->create();
        ChallengeFixtures::oneGap($otherProject);
        $otherRoadmap = app(GenerateRoadmap::class)->handle($otherProject, $this->owner)->roadmap;
        $otherStep = RoadmapStep::query()->where('roadmap_snapshot_id', $otherRoadmap->id)->firstOrFail();
        $this->assertRefused(fn () => DB::table('roadmap_step_completions')->insert(['id' => strtolower((string) Str::ulid()), 'roadmap_step_id' => $otherStep->id] + $completion),
            'roadmap_step_completions_step_foreign');
    }

    public function test_nothing_is_changed_or_deleted_through_the_models(): void
    {
        $step = RoadmapStep::query()->where('roadmap_snapshot_id', $this->roadmap->id)->firstOrFail();
        app(CompleteRoadmapStep::class)->handle($this->project, $this->roadmap, 'ch-syntax', $this->owner);
        $completion = RoadmapStepCompletion::query()->firstOrFail();

        foreach ([fn () => $this->roadmap->delete(), fn () => $step->delete(), fn () => $completion->delete(),
            fn () => $step->forceFill(['title' => 'x'])->save(), fn () => $completion->forceFill(['completed_at' => Carbon::now()->subYear()])->save()] as $i => $change) {
            try {
                $change();
                $this->fail("change {$i} was allowed");
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A stored roadmap is reproducible: regenerating from its skill gap
     * snapshot under the same versions gives the same fingerprint.
     */
    public function test_a_stored_roadmap_is_reproducible_from_its_skill_gap_snapshot(): void
    {
        $gaps = $this->roadmap->skillGapSnapshot()->firstOrFail();
        $results = $gaps->results->map(fn ($r): array => [
            'competency_key' => $r->competency_key, 'status' => $r->status->value, 'priority' => $r->priority?->value, 'priority_capped' => $r->priority_capped,
            'current_score' => $r->current_score, 'target_score' => $r->target_score, 'raw_gap' => $r->raw_gap,
            'evidence_quality' => $r->evidence_quality, 'current_level' => $r->current_level,
        ])->all();

        $plan = (new RoadmapGenerator(new DevelopmentFocusResolver, app(ChallengeSelector::class)))->generate(array_values($results), [
            'version' => $gaps->skill_gap_version, 'specification_fingerprint' => $gaps->specification_fingerprint,
            'target_profile' => $gaps->target_profile, 'target_profile_version' => $gaps->target_profile_version,
        ], app(RoadmapCatalog::class), app(RoadmapRules::class), app(ChallengeCatalog::class));

        $this->assertSame($this->roadmap->roadmap_fingerprint, $plan->fingerprint);
        $this->assertSame($this->roadmap->tracks, $plan->tracks);
        $this->assertSame(
            array_column($plan->steps, 'key'),
            RoadmapStep::query()->where('roadmap_snapshot_id', $this->roadmap->id)->orderBy('position')->pluck('step_key')->all(),
        );
    }

    public function test_the_status_enum_matches_the_database(): void
    {
        $this->assertSame(['ACTIVE', 'COMPLETED', 'SUPERSEDED'], array_column(RoadmapStatus::cases(), 'value'));
    }
}
