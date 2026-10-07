<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Challenge\SubmitChallengeSolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Challenge\ListChallengesRequest;
use App\Http\Requests\Challenge\StoreChallengeSubmissionRequest;
use App\Http\Resources\ChallengeSubmissionResource;
use App\Http\Resources\ChallengeSubmissionSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/challenges/{challenge}/submissions — submit an
 * attempt (queued for the isolated evaluator, never run here), list
 * attempts, read one with its source and feedback. Owner-only.
 */
final class ChallengeSubmissionController extends Controller
{
    /** Summaries never read the source. */
    private const SUMMARY_COLUMNS = ['id', 'challenge_instance_id', 'attempt_number', 'language', 'status', 'source_bytes', 'source_sha256',
        'evaluation', 'execution_status', 'failure_code', 'created_at', 'completed_at'];

    public function index(ListChallengesRequest $request, Project $project, ChallengeInstance $challengeInstance, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $submissions = $challengeInstance->challengeSubmissions()
            ->select(self::SUMMARY_COLUMNS)
            ->orderByDesc('attempt_number')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($submissions, ChallengeSubmissionSummaryResource::class);
    }

    /**
     * 202 with a new QUEUED submission, or 200 with the earlier one for the
     * same Idempotency-Key and source (Idempotent-Replayed: true).
     */
    public function store(StoreChallengeSubmissionRequest $request, Project $project, ChallengeInstance $challengeInstance, SubmitChallengeSolution $submit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $submitted = $submit->handle($challengeInstance, $user, $request->language(), $request->source(), $request->idempotencyKey());
        $response = (new ChallengeSubmissionResource($submitted->submission))->response();

        return $submitted->created
            ? $response->setStatusCode(202)
            : $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }

    public function show(Project $project, ChallengeInstance $challengeInstance, ChallengeSubmission $challengeSubmission, Gate $gate): ChallengeSubmissionResource
    {
        $gate->authorize('view', $project);

        return new ChallengeSubmissionResource($challengeSubmission);
    }
}
