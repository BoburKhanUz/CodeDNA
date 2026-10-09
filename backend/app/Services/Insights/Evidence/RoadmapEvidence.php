<?php

declare(strict_types=1);

namespace App\Services\Insights\Evidence;

use App\Enums\Insights\InsightFailure;
use App\Enums\Roadmap\RoadmapStatus;
use App\Models\RoadmapSnapshot;
use App\Models\RoadmapStep;
use App\Services\Insights\InsightException;

/**
 * Evidence for roadmap guidance (Phase 29): one ACTIVE learning roadmap
 * (Phase 17): its development focus (the deterministic skill gaps that
 * chose it), its catalog tracks and steps, and progress. Each step's state
 * is derived exactly as the roadmap API derives it: COMPLETED, AVAILABLE
 * (every prerequisite completed) or LOCKED. Catalog text is server-owned.
 */
final class RoadmapEvidence
{
    /** 3 tracks of at most 8 steps (Phase 17 rules); a bound, not a selection. */
    public const MAX_STEPS = 32;

    /**
     * @return array{evidence: list<array<string, mixed>>, lineage: array<string, string|null>, notes: list<string>}
     */
    public static function build(RoadmapSnapshot $roadmap): array
    {
        if ($roadmap->status !== RoadmapStatus::Active) {
            throw new InsightException(InsightFailure::EvidenceInvalid, 'roadmap_not_active');
        }
        $steps = RoadmapStep::query()->where('roadmap_snapshot_id', $roadmap->id)->orderBy('position')->limit(self::MAX_STEPS + 1)->get();
        if ($steps->count() > self::MAX_STEPS) {
            throw new InsightException(InsightFailure::InputTooLarge, 'steps');
        }
        $completedIds = $roadmap->completions()->pluck('roadmap_step_id')->all();
        $completedKeys = $steps->filter(fn (RoadmapStep $s): bool => in_array($s->id, $completedIds, true))->pluck('step_key')->all();

        $evidence = [Facts::item('roadmap:summary', 'Learning roadmap', 'The active learning roadmap and its progress. Completing steps does not change any score or gap.', [
            'tracks' => Facts::count(count($roadmap->tracks)),
            'steps' => Facts::count($steps->count()),
            'completed_steps' => Facts::count(count($completedKeys)),
        ])];
        foreach ((array) ($roadmap->focus['selected'] ?? []) as $focus) {
            $competency = Facts::token($focus['competency_key'] ?? null);
            $evidence[] = Facts::item("focus:{$competency}", "Skill gap {$competency}", 'The deterministic skill gap that selected this track.', [
                'competency' => $competency,
                'status' => Facts::token($focus['status'] ?? null),
                'priority' => Facts::optionalToken($focus['priority'] ?? null),
                'current_score' => Facts::decimal($focus['current_score'] ?? null),
                'target_score' => Facts::decimal($focus['target_score'] ?? null),
                'raw_gap' => Facts::decimal($focus['raw_gap'] ?? null),
                'evidence_quality' => Facts::decimal($focus['evidence_quality'] ?? null),
                'current_level' => Facts::optionalToken($focus['current_level'] ?? null),
            ]);
        }
        foreach ($roadmap->tracks as $track) {
            $competency = Facts::token($track['competency_key'] ?? null);
            $trackSteps = $steps->filter(fn (RoadmapStep $s): bool => $s->track_position === (int) ($track['position'] ?? -1));
            $evidence[] = Facts::item("track:{$competency}", Facts::catalogText($track['title'] ?? null, 120), Facts::catalogText($track['objective'] ?? null), [
                'competency' => $competency,
                'position' => Facts::count($track['position'] ?? null, 10),
                'steps' => Facts::count($trackSteps->count()),
                'completed_steps' => Facts::count($trackSteps->filter(fn (RoadmapStep $s): bool => in_array($s->step_key, $completedKeys, true))->count()),
                'estimated_minutes' => Facts::count($track['estimated_minutes'] ?? null, 100000),
            ]);
        }
        foreach ($steps as $step) {
            /** @var RoadmapStep $step */
            $key = Facts::key($step->step_key);
            $prerequisites = array_map(Facts::key(...), array_values((array) $step->prerequisites));
            $state = match (true) {
                in_array($key, $completedKeys, true) => 'COMPLETED',
                array_diff($prerequisites, $completedKeys) === [] => 'AVAILABLE',
                default => 'LOCKED',
            };
            $evidence[] = Facts::item("step:{$key}", Facts::catalogText($step->title, 120), Facts::catalogText($step->objective), [
                'track' => Facts::token($step->competency_key),
                'type' => Facts::token($step->type->value),
                'position' => Facts::count($step->step_position, 100),
                'estimated_minutes' => Facts::count($step->estimated_minutes, 10000),
                'prerequisites' => $prerequisites,
                'state' => $state,
            ]);
        }

        return [
            'evidence' => $evidence,
            'lineage' => [
                'project_id' => $roadmap->project_id,
                'user_id' => $roadmap->user_id,
                'roadmap_snapshot_id' => $roadmap->id,
                'skill_gap_snapshot_id' => $roadmap->skill_gap_snapshot_id,
                'completed_steps' => implode(',', $completedKeys),
            ],
            'notes' => [
                'The roadmap and its steps are deterministic and server-owned; only AVAILABLE steps can be taken next.',
                'Completing steps or challenges never changes a score, level or gap; only a new code analysis can.',
                'Only the listed steps exist: there are no external courses, links or resources.',
            ],
        ];
    }
}
