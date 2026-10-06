<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Competency\ListCompetencySnapshotsRequest;
use App\Http\Resources\CompetencySnapshotResource;
use App\Http\Resources\CompetencySnapshotSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\CompetencySnapshot;
use App\Models\Project;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * /api/v1/projects/{project}/competencies — read-only access to the
 * project's immutable competency snapshots (Phase 13,
 * docs/api/README.md#competencies). They are created by
 * CalculateCompetencyMatrix only; there is no write route, and nothing is
 * computed per request.
 */
final class CompetencySnapshotController extends Controller
{
    /** The list never reads the competencies and provenance documents. */
    private const SUMMARY_COLUMNS = [
        'id', 'project_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'competency_version',
        'dna_scoring_version', 'status', 'summary', 'created_at',
    ];

    public function index(ListCompetencySnapshotsRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $snapshots = $project->competencySnapshots()
            ->select(self::SUMMARY_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($snapshots, CompetencySnapshotSummaryResource::class);
    }

    public function show(Project $project, CompetencySnapshot $competencySnapshot, Gate $gate): CompetencySnapshotResource
    {
        // The route uses scoped bindings: the snapshot belongs to this project.
        $gate->authorize('view', $project);

        $competencySnapshot->load([
            'dnaSnapshot:id,project_id,status,overall_score,data_quality,scoring_version,created_at',
            'sourceSnapshot:id,project_id,version,file_count,primary_language,created_at',
            'analysisRun:id,project_id,result_type,status,completed_at',
        ]);

        return new CompetencySnapshotResource($competencySnapshot);
    }
}
