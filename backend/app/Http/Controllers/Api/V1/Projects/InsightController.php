<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Insights\RequestInsight;
use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\ListInsightsRequest;
use App\Http\Requests\Insights\StoreInsightRequest;
use App\Http\Resources\AiInsightResource;
use App\Models\AiInsight;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/insights — request and read AI insights about
 * a growth snapshot, a learning roadmap or an evaluated challenge
 * submission (Phase 29, docs/api/README.md#ai-insights). Reads need view
 * access to the project, requests the "assess" ability; insights of other
 * projects are never found. Requests only queue work: no model is called
 * during an HTTP request.
 */
final class InsightController extends Controller
{
    /** The newest insights of one subject, newest first (at most 10). */
    public function index(ListInsightsRequest $request, Project $project, Gate $gate): JsonResponse
    {
        $gate->authorize('view', $project);
        $insights = AiInsight::query()
            ->where('project_id', $project->id)
            ->where('kind', $request->kind()->value)
            ->where($request->kind()->subjectColumn(), $request->subjectId())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json(['data' => AiInsightResource::collection($insights)->toArray($request)]);
    }

    /**
     * 202 with a new QUEUED insight, or 200 with the existing one of the same
     * identity and Idempotent-Replayed: true.
     */
    public function store(StoreInsightRequest $request, Project $project, RequestInsight $requestInsight): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $requested = $requestInsight->handle($project, $user, $request->kind(), $request->subjectId());
        $response = (new AiInsightResource($requested->insight))->response();

        return $requested->created
            ? $response->setStatusCode(202)
            : $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }

    public function show(Project $project, string $insight, Gate $gate): AiInsightResource
    {
        $gate->authorize('view', $project);

        return new AiInsightResource(AiInsight::query()->where('project_id', $project->id)->whereKey(strtolower($insight))->firstOrFail());
    }
}
