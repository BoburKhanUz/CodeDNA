<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Assessment\RequestAssessment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\ListAssessmentsRequest;
use App\Http\Requests\Assessment\StoreAssessmentRequest;
use App\Http\Resources\AiAssessmentResource;
use App\Http\Resources\AiAssessmentSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\AiAssessment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/assessments — request, list and read AI
 * interpretations (Phase 15, docs/api/README.md#ai-assessments). Requests
 * only queue work: no provider is called during an HTTP request.
 */
final class AssessmentController extends Controller
{
    /** The list never reads the input or output documents. */
    private const SUMMARY_COLUMNS = [
        'id', 'project_id', 'skill_gap_snapshot_id', 'status', 'assessment_version', 'provider', 'model',
        'failure_code', 'created_at', 'completed_at',
    ];

    public function index(ListAssessmentsRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $assessments = $project->aiAssessments()
            ->select(self::SUMMARY_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($assessments, AiAssessmentSummaryResource::class);
    }

    /**
     * 202 with a new QUEUED assessment, or 200 with the existing one of the
     * same identity (QUEUED, RUNNING or SUCCEEDED) and Idempotent-Replayed: true.
     */
    public function store(StoreAssessmentRequest $request, Project $project, RequestAssessment $requestAssessment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requested = $requestAssessment->handle($project, $user, $request->skillGapSnapshotId());
        $response = (new AiAssessmentResource($requested->assessment))->response();

        if ($requested->created) {
            return $response->setStatusCode(202);
        }

        return $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }

    public function show(Project $project, AiAssessment $aiAssessment, Gate $gate): AiAssessmentResource
    {
        // The route uses scoped bindings: the assessment belongs to this project.
        $gate->authorize('view', $project);

        return new AiAssessmentResource($aiAssessment);
    }
}
