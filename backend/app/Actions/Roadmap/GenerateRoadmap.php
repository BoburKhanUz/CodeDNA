<?php

declare(strict_types=1);

namespace App\Actions\Roadmap;

use App\Enums\Roadmap\RoadmapStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\RoadmapStep;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapGenerationException;
use App\Services\Roadmap\RoadmapGenerator;
use App\Services\Roadmap\RoadmapRules;
use App\Services\SkillGap\SkillGapSpecification;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates the learning roadmap of a project's newest skill gap snapshot
 * (docs/architecture/learning-roadmap-v1.md#generation). Explicit and
 * idempotent: the same snapshot, roadmap version and rules version always
 * give the same roadmap, created once. Read-only towards every analysis
 * table, and no AI.
 *
 * - A roadmap that already exists for the newest snapshot is returned.
 * - Otherwise the snapshot is checked against its skill gap specification,
 *   the roadmap is generated (RoadmapGenerator) and stored, and the
 *   project's previous ACTIVE roadmap becomes SUPERSEDED (kept, readable).
 * - A snapshot without an actionable gap gives no roadmap, and the
 *   existing roadmap is left as it is.
 *
 * Concurrency: the project row is locked for the decision; the unique
 * (skill gap snapshot, versions) key and the one-ACTIVE-per-project index
 * are the database backstop.
 */
final readonly class GenerateRoadmap
{
    public function __construct(
        private ConnectionInterface $db,
        private RoadmapCatalog $catalog,
        private RoadmapRules $rules,
        private ChallengeCatalog $challenges,
        private RoadmapGenerator $generator,
    ) {}

    public function handle(Project $project, User $actor): GeneratedRoadmap
    {
        return $this->db->transaction(fn (): GeneratedRoadmap => $this->generate($project, $actor));
    }

    private function generate(Project $project, User $actor): GeneratedRoadmap
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and cannot receive a new roadmap.');
        }
        $gaps = SkillGapSnapshot::query()->where('project_id', $locked->id)->orderByDesc('created_at')->orderByDesc('id')->first()
            ?? throw new ApiException(ErrorCode::RoadmapNoSkillGaps);

        $existing = RoadmapSnapshot::query()
            ->where('skill_gap_snapshot_id', $gaps->id)
            ->where('roadmap_version', $this->catalog->version())
            ->where('rules_version', $this->rules->version)
            ->first();
        if ($existing !== null) {
            return new GeneratedRoadmap($existing, false);
        }

        $specification = $this->specification($gaps);
        $results = SkillGapResult::query()->where('skill_gap_snapshot_id', $gaps->id)->orderBy('position')->get()
            ->map(fn (SkillGapResult $r): array => [
                'competency_key' => $r->competency_key,
                'status' => $r->status->value,
                'priority' => $r->priority?->value,
                'priority_capped' => $r->priority_capped,
                'current_score' => $r->current_score,
                'target_score' => $r->target_score,
                'raw_gap' => $r->raw_gap,
                'evidence_quality' => $r->evidence_quality,
                'current_level' => $r->current_level,
            ])->all();

        try {
            $plan = $this->generator->generate(array_values($results), [
                'version' => $gaps->skill_gap_version,
                'specification_fingerprint' => $specification->fingerprint(),
                'target_profile' => $gaps->target_profile,
                'target_profile_version' => $gaps->target_profile_version,
            ], $this->catalog, $this->rules, $this->challenges);
        } catch (RoadmapGenerationException) {
            throw new ApiException(ErrorCode::RoadmapNoActionableGaps);
        }

        $id = strtolower((string) Str::ulid());
        $previous = RoadmapSnapshot::query()->where('project_id', $locked->id)->where('status', RoadmapStatus::Active->value)->lockForUpdate()->get();
        foreach ($previous as $old) {
            $old->forceFill(['status' => RoadmapStatus::Superseded, 'superseded_by_id' => $id, 'superseded_at' => Carbon::now()])->save();
        }

        $roadmap = new RoadmapSnapshot;
        $roadmap->forceFill([
            'id' => $id,
            'user_id' => $gaps->user_id,
            'project_id' => $gaps->project_id,
            'skill_gap_snapshot_id' => $gaps->id,
            'competency_snapshot_id' => $gaps->competency_snapshot_id,
            'dna_snapshot_id' => $gaps->dna_snapshot_id,
            'analysis_run_id' => $gaps->analysis_run_id,
            'source_snapshot_id' => $gaps->source_snapshot_id,
            'roadmap_version' => $this->catalog->version(),
            'rules_version' => $this->rules->version,
            'catalog_fingerprint' => $this->catalog->fingerprint(),
            'rules_fingerprint' => $this->rules->fingerprint(),
            'roadmap_fingerprint' => $plan->fingerprint,
            'skill_gap_version' => $gaps->skill_gap_version,
            'skill_gap_specification_fingerprint' => $gaps->specification_fingerprint,
            'target_profile' => $gaps->target_profile,
            'target_profile_version' => $gaps->target_profile_version,
            'challenge_catalog_version' => $this->challenges->version(),
            'challenge_catalog_fingerprint' => $this->challenges->fingerprint(),
            'focus' => $plan->focus->toArray(),
            'tracks' => $plan->tracks,
            'step_count' => count($plan->steps),
            'estimated_minutes' => array_sum(array_column($plan->tracks, 'estimated_minutes')),
            'status' => RoadmapStatus::Active,
        ]);
        $roadmap->save();

        foreach ($plan->steps as $step) {
            $row = new RoadmapStep;
            $row->forceFill([
                'roadmap_snapshot_id' => $roadmap->id,
                'project_id' => $roadmap->project_id,
                'user_id' => $roadmap->user_id,
                'position' => $step['position'],
                'track_position' => $step['track_position'],
                'step_position' => $step['step_position'],
                'track_key' => $step['track_key'],
                'competency_key' => $step['competency_key'],
                'step_key' => $step['key'],
                'type' => $step['type'],
                'title' => $step['title'],
                'description' => $step['description'],
                'objective' => $step['objective'],
                'estimated_minutes' => $step['estimated_minutes'],
                'prerequisites' => $step['prerequisites'],
                'challenge_key' => $step['challenge']['key'] ?? null,
                'challenge_version' => $step['challenge']['version'] ?? null,
                'challenge_title' => $step['challenge']['title'] ?? null,
                'challenge_difficulty' => $step['challenge']['difficulty'] ?? null,
            ]);
            $row->save();
        }

        Log::info('roadmap.generated', [
            'roadmap_id' => $roadmap->id,
            'project_id' => $roadmap->project_id,
            'skill_gap_snapshot_id' => $roadmap->skill_gap_snapshot_id,
            'roadmap_version' => $roadmap->roadmap_version,
            'rules_version' => $roadmap->rules_version,
            'focus' => array_column($plan->focus->selected, 'competency_key'),
            'steps' => $roadmap->step_count,
            'superseded' => $previous->pluck('id')->all(),
            'requested_by' => $actor->getKey(),
            'request_id' => Context::get('request_id'),
        ]);

        return new GeneratedRoadmap($roadmap, true);
    }

    /**
     * The skill gap specification the snapshot was computed with; a
     * snapshot whose stored fingerprint or profile does not match it is
     * rejected, never reinterpreted.
     */
    private function specification(SkillGapSnapshot $gaps): SkillGapSpecification
    {
        try {
            $specification = SkillGapSpecification::forVersion($gaps->skill_gap_version);
        } catch (InvalidArgumentException) {
            throw new ApiException(ErrorCode::RoadmapEvidenceInvalid);
        }
        if ($gaps->specification_fingerprint !== $specification->fingerprint()
            || $gaps->target_profile !== $specification->targetProfile->key
            || $gaps->target_profile_version !== $specification->targetProfile->version) {
            throw new ApiException(ErrorCode::RoadmapEvidenceInvalid);
        }

        return $specification;
    }
}
