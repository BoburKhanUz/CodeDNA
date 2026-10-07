<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Roadmap\RoadmapStatus;
use App\Enums\Roadmap\RoadmapStepType;
use App\Models\ChallengeInstance;
use App\Models\RoadmapSnapshot;
use App\Models\RoadmapStep;
use App\Models\RoadmapStepCompletion;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One learning roadmap: the development focus and why, the tracks with
 * their ordered steps and progress, the challenge practice of each track,
 * provenance, versions and fingerprints. Everything comes from the stored
 * roadmap; nothing here is a score, and nothing changes a gap.
 *
 * The controller loads steps and completions and sets $practice (the
 * newest challenge per competency for the roadmap's skill gap snapshot).
 *
 * @mixin RoadmapSnapshot
 */
final class RoadmapResource extends JsonResource
{
    public const NOTICE = 'Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.';

    /** @var array<string, ChallengeInstance> keyed by competency */
    public array $practice = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChallengeCatalog $challenges */
        $challenges = app(ChallengeCatalog::class);
        /** @var RoadmapCatalog $catalog */
        $catalog = app(RoadmapCatalog::class);
        /** @var RoadmapRules $rules */
        $rules = app(RoadmapRules::class);

        $completedAt = [];
        foreach ($this->completions as $completion) {
            /** @var RoadmapStepCompletion $completion */
            $completedAt[$completion->roadmap_step_id] = $completion->completed_at->toIso8601ZuluString();
        }
        $completedKeys = [];
        foreach ($this->steps as $step) {
            if (isset($completedAt[$step->id])) {
                $completedKeys[] = $step->step_key;
            }
        }
        $active = $this->status === RoadmapStatus::Active;
        $focusByCompetency = array_column($this->focus['selected'] ?? [], null, 'competency_key');

        $tracks = array_map(function (array $track) use ($completedAt, $completedKeys, $active, $challenges, $focusByCompetency): array {
            $steps = $this->steps->filter(fn (RoadmapStep $s): bool => $s->track_position === $track['position'])->values();
            $done = $steps->filter(fn (RoadmapStep $s): bool => isset($completedAt[$s->id]))->count();
            $practice = $this->practice[$track['competency_key']] ?? null;

            return [
                'position' => $track['position'],
                'key' => $track['key'],
                'version' => $track['version'],
                'competency_key' => $track['competency_key'],
                'title' => $track['title'],
                'description' => $track['description'],
                'objective' => $track['objective'],
                'estimated_minutes' => $track['estimated_minutes'],
                'focus' => $focusByCompetency[$track['competency_key']] ?? null,
                'progress' => ['completed' => $done, 'total' => $steps->count()],
                'steps' => $steps->map(fn (RoadmapStep $s): array => [
                    'key' => $s->step_key,
                    'position' => $s->position,
                    'type' => $s->type->value,
                    'title' => $s->title,
                    'description' => $s->description,
                    'objective' => $s->objective,
                    'estimated_minutes' => $s->estimated_minutes,
                    'prerequisites' => $s->prerequisites,
                    'completed_at' => $completedAt[$s->id] ?? null,
                    'can_complete' => $active && ! isset($completedAt[$s->id]) && array_diff($s->prerequisites, $completedKeys) === [],
                    'challenge' => $s->challenge_key === null ? null : [
                        'key' => $s->challenge_key,
                        'version' => $s->challenge_version,
                        'title' => $s->challenge_title,
                        'difficulty' => $s->challenge_difficulty,
                        'in_catalog' => $challenges->find($s->challenge_key, (string) $s->challenge_version) !== null,
                    ],
                    'practice' => $s->type === RoadmapStepType::Challenge && $practice !== null
                        ? ['challenge_id' => $practice->id, 'definition_key' => $practice->definition_key, 'status' => $practice->status->value]
                        : null,
                ])->all(),
            ];
        }, $this->tracks);

        return [
            ...(new RoadmapSummaryResource($this->resource))->toArray($request),
            'progress' => ['completed' => count($completedAt), 'total' => $this->step_count],
            'notice' => self::NOTICE,
            'development_focus' => [
                'selected' => $this->focus['selected'] ?? [],
                'excluded' => $this->focus['excluded'] ?? [],
            ],
            'tracks' => $tracks,
            'lineage' => [
                'skill_gap_snapshot_id' => $this->skill_gap_snapshot_id,
                'competency_snapshot_id' => $this->competency_snapshot_id,
                'dna_snapshot_id' => $this->dna_snapshot_id,
                'analysis_run_id' => $this->analysis_run_id,
                'source_snapshot_id' => $this->source_snapshot_id,
            ],
            'versions' => [
                'roadmap' => $this->roadmap_version,
                'rules' => $this->rules_version,
                'skill_gap' => $this->skill_gap_version,
                'target_profile' => ['key' => $this->target_profile, 'version' => $this->target_profile_version],
                'challenge_catalog' => $this->challenge_catalog_version,
            ],
            'fingerprints' => [
                'roadmap' => $this->roadmap_fingerprint,
                'catalog' => $this->catalog_fingerprint,
                'rules' => $this->rules_fingerprint,
                'skill_gap_specification' => $this->skill_gap_specification_fingerprint,
                'challenge_catalog' => $this->challenge_catalog_fingerprint,
            ],
            // Whether the server still uses the catalog and rules this roadmap was generated with.
            'current' => [
                'catalog' => $this->roadmap_version === $catalog->version() && $this->catalog_fingerprint === $catalog->fingerprint(),
                'rules' => $this->rules_version === $rules->version && $this->rules_fingerprint === $rules->fingerprint(),
            ],
            'superseded_by' => $this->superseded_by_id,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
