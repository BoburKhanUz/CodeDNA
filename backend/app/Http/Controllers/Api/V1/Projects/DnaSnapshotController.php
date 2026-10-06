<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dna\ListDnaSnapshotsRequest;
use App\Http\Resources\DnaSnapshotResource;
use App\Http\Resources\DnaSnapshotSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\DnaSnapshot;
use App\Models\Project;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * /api/v1/projects/{project}/dna — read-only access to the project's
 * immutable DNA snapshots (Phase 12, docs/api/README.md#dna). Snapshots are
 * created by the scoring engine only (Phase 11); there is no write route.
 */
final class DnaSnapshotController extends Controller
{
    /** Columns of the list: the large dimensions and evidence documents are not read. */
    private const SUMMARY_COLUMNS = [
        'id', 'project_id', 'analysis_run_id', 'source_snapshot_id', 'status', 'overall_score', 'data_quality',
        'scoring_version', 'metrics_version', 'created_at',
    ];

    public function index(ListDnaSnapshotsRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $snapshots = $project->dnaSnapshots()
            ->select(self::SUMMARY_COLUMNS)
            ->with('sourceSnapshot:id,project_id,version')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($snapshots, DnaSnapshotSummaryResource::class);
    }

    public function show(Project $project, DnaSnapshot $dnaSnapshot, Gate $gate): DnaSnapshotResource
    {
        // The route uses scoped bindings: the snapshot belongs to this project.
        $gate->authorize('view', $project);

        $dnaSnapshot->load([
            'sourceSnapshot:id,project_id,version,file_count,primary_language,created_at',
            'analysisRun:id,project_id,result_type,status,completed_at',
        ]);

        return new DnaSnapshotResource($dnaSnapshot);
    }
}
