<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Roadmap\GenerateRoadmap;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roadmap\ListRoadmapsRequest;
use App\Http\Requests\Roadmap\StoreRoadmapRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\RoadmapResource;
use App\Http\Resources\RoadmapSummaryResource;
use App\Models\ChallengeInstance;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/roadmaps — generate, list and read learning
 * roadmaps (Phase 17, docs/api/README.md#learning-roadmaps). A planning
 * layer: nothing here changes CodeDNA, competencies or skill gaps.
 */
final class RoadmapController extends Controller
{
    public function index(ListRoadmapsRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $roadmaps = $project->roadmapSnapshots()
            ->withCount('completions')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($roadmaps, RoadmapSummaryResource::class);
    }

    /**
     * 201 with a new roadmap, or 200 with the roadmap that already exists
     * for the newest skill gap analysis and Idempotent-Replayed: true.
     */
    public function store(StoreRoadmapRequest $request, Project $project, GenerateRoadmap $generate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $generated = $generate->handle($project, $user);
        $response = self::detail($generated->roadmap)->response();

        return $generated->created
            ? $response->setStatusCode(201)
            : $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }

    public function show(Project $project, RoadmapSnapshot $roadmapSnapshot, Gate $gate): RoadmapResource
    {
        // Scoped binding: the roadmap belongs to this project.
        $gate->authorize('view', $project);

        return self::detail($roadmapSnapshot);
    }

    /**
     * A roadmap with its steps, completions and challenge practice: four
     * bounded queries, whatever the number of steps.
     */
    public static function detail(RoadmapSnapshot $roadmap): RoadmapResource
    {
        $roadmap->load(['steps', 'completions']);
        $roadmap->loadCount('completions');
        $practice = [];
        ChallengeInstance::query()
            ->select(['id', 'competency_key', 'definition_key', 'status', 'created_at'])
            ->where('skill_gap_snapshot_id', $roadmap->skill_gap_snapshot_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (ChallengeInstance $instance) use (&$practice): void {
                $practice[$instance->competency_key] = $instance;
            });
        $resource = new RoadmapResource($roadmap);
        $resource->practice = $practice;

        return $resource;
    }
}
