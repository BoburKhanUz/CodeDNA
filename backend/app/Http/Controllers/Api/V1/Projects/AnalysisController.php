<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Analysis\StartAnalysis;
use App\Enums\AnalysisRunStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Errors\ErrorCode;
use App\Http\Pagination\KeysetPaginator;
use App\Http\Requests\Analysis\ListAnalysesRequest;
use App\Http\Requests\Analysis\StoreAnalysisRequest;
use App\Http\Resources\AnalysisRunResource;
use App\Http\Resources\CursorCollection;
use App\Http\Resources\PaginatedCollection;
use App\Models\AnalysisResult;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/analyses — start, list and inspect analysis
 * runs, and read a successful run's verified result (Phase 10).
 */
final class AnalysisController extends Controller
{
    public function index(ListAnalysesRequest $request, Project $project, Gate $gate, KeysetPaginator $keyset): PaginatedCollection|CursorCollection
    {
        $gate->authorize('view', $project);

        if ($request->usesCursor()) {
            return new CursorCollection($keyset->paginate(
                $project->analysisRuns()->getQuery(), ['analysis_runs.created_at', 'analysis_runs.id'],
                fn (AnalysisRun $run): array => [(string) $run->getRawOriginal('created_at'), $run->id],
                'analyses:'.$project->id, $request->cursor(), $request->perPage(), indexPrefix: 1,
            ), AnalysisRunResource::class);
        }

        $runs = $project->analysisRuns()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($runs, AnalysisRunResource::class);
    }

    /**
     * 202 with a new QUEUED run, or 200 with the existing equivalent run
     * (QUEUED, RUNNING or SUCCEEDED) and Idempotent-Replayed: true.
     */
    public function store(StoreAnalysisRequest $request, Project $project, StartAnalysis $startAnalysis): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $started = $startAnalysis->handle($project, $user, $request->sourceSnapshotId(), $request->resultType());
        $response = (new AnalysisRunResource($started->run))->response();

        if ($started->created) {
            return $response->setStatusCode(202);
        }

        return $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }

    public function show(Project $project, AnalysisRun $analysisRun, Gate $gate): AnalysisRunResource
    {
        // The route uses scoped bindings: the run belongs to this project.
        $gate->authorize('view', $project);

        return new AnalysisRunResource($analysisRun);
    }

    /**
     * The verified analyzer result of a SUCCEEDED run, as stored. It holds
     * structure, counts, metrics and findings only, never source text.
     */
    public function result(Project $project, AnalysisRun $analysisRun, Gate $gate): JsonResponse
    {
        $gate->authorize('view', $project);

        if ($analysisRun->status !== AnalysisRunStatus::Succeeded) {
            throw new ApiException(ErrorCode::AnalysisNotCompleted);
        }

        // Read the stored JSON text as is: no decode/encode round trip.
        $stored = AnalysisResult::query()->whereKey($analysisRun->id)->toBase()->first(['result_type', 'result_hash', 'result']);
        if ($stored === null) {
            throw new ApiException(ErrorCode::ResourceNotFound);
        }

        $body = '{"data":{"analysis_run_id":'.json_encode($analysisRun->id).',"type":"analysis_result","result_type":'
            .json_encode($stored->result_type).',"result_hash":'.json_encode($stored->result_hash).',"result":'.$stored->result.'}}';

        return new JsonResponse($body, 200, [], 0, true);
    }
}
