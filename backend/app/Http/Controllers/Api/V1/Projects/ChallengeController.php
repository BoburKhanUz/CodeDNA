<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Challenge\AssignChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Challenge\ListChallengesRequest;
use App\Http\Requests\Challenge\StoreChallengeRequest;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ChallengeSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\ChallengeInstance;
use App\Models\Project;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/challenges — assign, list and read coding
 * challenges (Phase 16, docs/api/README.md#coding-challenges). A practice
 * layer: nothing here changes CodeDNA, competencies or skill gaps.
 */
final class ChallengeController extends Controller
{
    /** The definition's document (hidden cases) is never loaded for a list. */
    private const DEFINITION_COLUMNS = 'definition:id,key,version,title';

    public function index(ListChallengesRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $challenges = $project->challengeInstances()
            ->with(self::DEFINITION_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($challenges, ChallengeSummaryResource::class);
    }

    /**
     * 201 with a new challenge, or 200 with the existing active one and
     * Idempotent-Replayed: true.
     */
    public function store(StoreChallengeRequest $request, Project $project, AssignChallenge $assign, ChallengeEvaluator $evaluator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $assigned = $assign->handle($project, $user, $request->skillGapSnapshotId(), $request->competencyKey());
        $response = $this->detail($assigned->instance, $evaluator)->response();

        return $assigned->created
            ? $response->setStatusCode(201)
            : $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }

    public function show(Project $project, ChallengeInstance $challengeInstance, Gate $gate, ChallengeEvaluator $evaluator): ChallengeResource
    {
        // Scoped binding: the challenge belongs to this project.
        $gate->authorize('view', $project);

        return $this->detail($challengeInstance, $evaluator);
    }

    private function detail(ChallengeInstance $challenge, ChallengeEvaluator $evaluator): ChallengeResource
    {
        $challenge->load([
            'definition',
            'submissions' => fn ($query) => $query
                ->select(['id', 'challenge_instance_id', 'attempt_number', 'language', 'status', 'source_bytes', 'source_sha256', 'evaluation',
                    'execution_status', 'failure_code', 'created_at', 'completed_at'])
                ->limit(ChallengeResource::RECENT_ATTEMPTS),
        ]);
        $resource = new ChallengeResource($challenge);
        $resource->evaluationAvailable = config('codedna.challenges.enabled') === true && $evaluator->available();

        return $resource;
    }
}
