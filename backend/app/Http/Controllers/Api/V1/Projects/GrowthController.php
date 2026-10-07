<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Enums\AnalysisRunStatus;
use App\Enums\Challenge\SubmissionStatus;
use App\Enums\Growth\GrowthSnapshotStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Growth\ListGrowthRequest;
use App\Http\Resources\GrowthSnapshotResource;
use App\Http\Resources\GrowthSnapshotSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Services\Growth\GrowthRules;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/projects/{project}/growth — read-only growth tracking (Phase 18,
 * docs/api/README.md#growth-tracking). Every value comes from stored growth
 * snapshots; there is no write route and no client-chosen snapshot,
 * metric, value or rules version.
 */
final class GrowthController extends Controller
{
    /** The longest trend shown: this many growth snapshots, i.e. one more assessment. */
    public const SERIES_SNAPSHOTS = 10;

    /**
     * The project's current growth: the newest assessment's growth snapshot,
     * the state when there is none, and trend series along the unbroken
     * chain of compared assessments. Bounded: a fixed number of queries.
     */
    public function overview(Project $project, Gate $gate, GrowthRules $rules): JsonResponse
    {
        $gate->authorize('view', $project);

        $newestAssessment = SkillGapSnapshot::query()
            ->select('skill_gap_snapshots.id')
            ->join('analysis_runs', 'analysis_runs.id', '=', 'skill_gap_snapshots.analysis_run_id')
            ->where('skill_gap_snapshots.project_id', $project->id)
            ->where('analysis_runs.status', AnalysisRunStatus::Succeeded->value)
            ->orderByDesc('analysis_runs.completed_at')
            ->orderByDesc('analysis_runs.id')
            ->orderByDesc('skill_gap_snapshots.id')
            ->first();
        $recent = $project->growthSnapshots()
            ->where('rules_version', $rules->version)
            ->with('observations')
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->limit(self::SERIES_SNAPSHOTS)
            ->get();
        $latest = $recent->first();

        $state = match (true) {
            $newestAssessment === null => 'NO_ASSESSMENT',
            $latest === null || $latest->skill_gap_snapshot_id !== $newestAssessment->id => 'NOT_CALCULATED',
            default => $latest->status->value,
        };

        return response()->json(['data' => [
            'state' => $state,
            'notice' => GrowthSnapshotResource::NOTICE,
            'latest' => $latest === null ? null : self::detail($latest)->toArray(request()),
            'series' => self::series($recent->all()),
        ]]);
    }

    /**
     * Every growth snapshot of the project, newest assessment first.
     */
    public function timeline(ListGrowthRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $snapshots = $project->growthSnapshots()
            ->with('observations')
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($snapshots, GrowthSnapshotSummaryResource::class);
    }

    public function show(Project $project, GrowthSnapshot $growthSnapshot, Gate $gate): GrowthSnapshotResource
    {
        // Scoped binding: the growth snapshot belongs to this project.
        $gate->authorize('view', $project);

        return self::detail($growthSnapshot);
    }

    private static function detail(GrowthSnapshot $snapshot): GrowthSnapshotResource
    {
        $snapshot->loadMissing('observations');
        $resource = new GrowthSnapshotResource($snapshot);
        $resource->activity = self::activity($snapshot);

        return $resource;
    }

    /**
     * Learning activity between the two assessments, as context only. One
     * query; null without a baseline.
     *
     * @return array{roadmap_steps_completed: int, challenges_passed: int}|null
     */
    private static function activity(GrowthSnapshot $snapshot): ?array
    {
        if ($snapshot->previous_assessed_at === null) {
            return null;
        }
        $window = [$snapshot->previous_assessed_at, $snapshot->assessed_at];
        $row = DB::selectOne(
            'SELECT
                (SELECT count(*) FROM roadmap_step_completions WHERE project_id = ? AND completed_at > ? AND completed_at <= ?) AS steps,
                (SELECT count(*) FROM challenge_submissions WHERE project_id = ? AND status = ? AND completed_at > ? AND completed_at <= ?) AS challenges',
            [$snapshot->project_id, ...$window, $snapshot->project_id, SubmissionStatus::Passed->value, ...$window],
        );

        return ['roadmap_steps_completed' => (int) $row->steps, 'challenges_passed' => (int) $row->challenges];
    }

    /**
     * Values per metric along the newest unbroken chain of COMPARED
     * snapshots, oldest first: the chain's first baseline, then every
     * compared assessment. A chain of one comparison is a before/after pair;
     * nothing is drawn across a missing baseline or a version change.
     *
     * @param  list<GrowthSnapshot>  $recent  newest first
     * @return list<array{metric_type: string, metric_key: string, points: list<array{assessed_at: string, value: string|null}>}>
     */
    private static function series(array $recent): array
    {
        $chain = [];
        foreach ($recent as $snapshot) {
            if ($snapshot->status !== GrowthSnapshotStatus::Compared) {
                break;
            }
            array_unshift($chain, $snapshot);
        }
        if ($chain === []) {
            return [];
        }
        $series = [];
        foreach ($chain as $i => $snapshot) {
            foreach ($snapshot->observations as $observation) {
                /** @var GrowthObservation $observation */
                $id = $observation->metric_type->value.':'.$observation->metric_key;
                $series[$id] ??= ['metric_type' => $observation->metric_type->value, 'metric_key' => $observation->metric_key, 'points' => []];
                if ($i === 0) {
                    $series[$id]['points'][] = ['assessed_at' => $snapshot->previous_assessed_at?->toIso8601ZuluString(), 'value' => $observation->previous_value];
                }
                $series[$id]['points'][] = ['assessed_at' => $snapshot->assessed_at->toIso8601ZuluString(), 'value' => $observation->current_value];
            }
        }

        return array_values($series);
    }
}
