<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Roadmap\CompleteRoadmapStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roadmap\CompleteRoadmapStepRequest;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/projects/{project}/roadmaps/{roadmap}/steps/{step}/complete —
 * self-reported learning progress (Phase 17). It changes only the
 * roadmap's progress, never a score, a competency or a gap.
 */
final class RoadmapStepController extends Controller
{
    /**
     * 200 with the updated roadmap; a step that was already completed
     * answers the same, with Idempotent-Replayed: true.
     */
    public function complete(CompleteRoadmapStepRequest $request, Project $project, RoadmapSnapshot $roadmapSnapshot, string $step, CompleteRoadmapStep $complete): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $completed = $complete->handle($project, $roadmapSnapshot, $step, $user);
        $response = RoadmapController::detail($completed->roadmap)->response()->setStatusCode(200);

        return $completed->created ? $response : $response->header('Idempotent-Replayed', 'true');
    }
}
