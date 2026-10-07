<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\History\CompareHistoryRequest;
use App\Http\Requests\History\ListHistoryRequest;
use App\Http\Resources\HistoryPointResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\GrowthObservation;
use App\Models\Project;
use App\Services\Growth\GrowthEvents;
use App\Services\Growth\GrowthRules;
use App\Services\Growth\LearningActivity;
use App\Services\History\HistoryComparer;
use App\Services\History\HistoryReader;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * /api/v1/projects/{project}/history — read-only historical DNA (Phase 20,
 * docs/api/README.md#historical-dna). A read model over the project's
 * immutable DNA, competency, skill gap and growth snapshots: nothing is
 * scored, assessed or stored here, and there is no write route. The only
 * client input is pagination and the IDs of two points to compare.
 */
final class HistoryController extends Controller
{
    public const NOTICE = 'Historical DNA shows each code assessment exactly as it was recorded. Stored values are never recalculated or rewritten, and assessments measured with different versions are never compared. Learning activity is context only.';

    /**
     * Every point of the project's history, newest first.
     */
    public function index(ListHistoryRequest $request, Project $project, Gate $gate, HistoryReader $reader): PaginatedCollection
    {
        $gate->authorize('view', $project);

        return new PaginatedCollection($reader->page($project, $request->page(), $request->perPage()), HistoryPointResource::class);
    }

    /**
     * One point, its neighbours, and learning activity since the previous
     * point as context only.
     */
    public function show(Request $request, Project $project, string $dnaSnapshot, Gate $gate, HistoryReader $reader): JsonResponse
    {
        $gate->authorize('view', $project);
        $point = $reader->find($project, $dnaSnapshot) ?? throw new ApiException(ErrorCode::ResourceNotFound);
        $neighbours = $reader->neighbours($project, $point);
        $previous = $neighbours['previous'];

        return response()->json(['data' => [
            ...(new HistoryPointResource($point))->toArray($request),
            'notice' => self::NOTICE,
            'previous' => $previous,
            'next' => $neighbours['next'],
            // Context only: never history or growth evidence.
            'activity' => $previous === null ? null : LearningActivity::between($project->id, Carbon::parse($previous['analyzed_at']), $point->analyzedAt()),
        ]]);
    }

    /**
     * Two points of this project, ordered by time and compared with the
     * Phase 18 rules (HistoryComparer). Both must be eligible points of
     * this project: anything else is 404, exactly like a missing one.
     */
    public function compare(CompareHistoryRequest $request, Project $project, Gate $gate, HistoryReader $reader, HistoryComparer $comparer, GrowthRules $rules): JsonResponse
    {
        $gate->authorize('view', $project);
        $points = $reader->findMany($project, [$request->fromId(), $request->toId()]);
        if (count($points) !== 2) {
            throw new ApiException(ErrorCode::ResourceNotFound);
        }
        [$from, $to] = $points;
        $comparison = $comparer->compare($from, $to);

        $grouped = ['DNA' => [], 'COMPETENCY' => [], 'SKILL_GAP' => []];
        foreach ($comparison->observations as $observation) {
            /** @var GrowthObservation $observation */
            $grouped[$observation->metric_type->value][] = [
                'metric_key' => $observation->metric_key,
                'better' => $observation->better,
                'previous_state' => $observation->previous_state,
                'current_state' => $observation->current_state,
                'previous_value' => $observation->previous_value,
                'current_value' => $observation->current_value,
                'delta' => $observation->delta,
                'previous_level' => $observation->previous_level,
                'current_level' => $observation->current_level,
                'level_change' => $observation->level_change,
                'previous_evidence_quality' => $observation->previous_evidence_quality,
                'current_evidence_quality' => $observation->current_evidence_quality,
                'status' => $observation->status->value,
            ];
        }
        $layer = fn (string $type): string => in_array($type, $comparison->layers, true) ? $comparison->status->value : 'UNAVAILABLE';

        return response()->json(['data' => [
            'type' => 'history_comparison',
            'notice' => self::NOTICE,
            'status' => $comparison->status->value,
            'basis' => $comparison->basis,
            'growth_snapshot_id' => $comparison->growthSnapshot?->id,
            'rules' => ['version' => $rules->version, 'fingerprint' => $rules->fingerprint()],
            'from' => (new HistoryPointResource($from))->toArray($request),
            'to' => (new HistoryPointResource($to))->toArray($request),
            'layers' => ['dna' => $layer('DNA'), 'competency' => $layer('COMPETENCY'), 'skill_gaps' => $layer('SKILL_GAP')],
            'differences' => $comparison->differences,
            'summary' => HistoryPointResource::growthSummary($comparison->summary),
            'events' => GrowthEvents::from($comparison->observations),
            'dna' => $grouped['DNA'],
            'competencies' => $grouped['COMPETENCY'],
            'skill_gaps' => $grouped['SKILL_GAP'],
            // Context only: never history or growth evidence.
            'activity' => LearningActivity::between($project->id, $from->analyzedAt(), $to->analyzedAt()),
        ]]);
    }
}
