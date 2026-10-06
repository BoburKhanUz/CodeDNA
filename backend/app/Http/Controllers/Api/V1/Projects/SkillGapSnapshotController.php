<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\SkillGap\ListSkillGapSnapshotsRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\SkillGapSnapshotResource;
use App\Http\Resources\SkillGapSnapshotSummaryResource;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * /api/v1/projects/{project}/skill-gaps — read-only access to the project's
 * immutable skill gap snapshots (Phase 14, docs/api/README.md#skill-gaps).
 * They are created by CalculateSkillGapSnapshot only; there is no write
 * route, no client-supplied target, and nothing is computed per request.
 */
final class SkillGapSnapshotController extends Controller
{
    /** The list never reads the provenance document or the result rows. */
    private const SUMMARY_COLUMNS = [
        'id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id',
        'skill_gap_version', 'target_profile', 'target_profile_version', 'competency_version', 'status', 'summary', 'created_at',
    ];

    public function index(ListSkillGapSnapshotsRequest $request, Project $project, Gate $gate): PaginatedCollection
    {
        $gate->authorize('view', $project);

        $snapshots = $project->skillGapSnapshots()
            ->select(self::SUMMARY_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($snapshots, SkillGapSnapshotSummaryResource::class);
    }

    public function show(Project $project, SkillGapSnapshot $skillGapSnapshot, Gate $gate): SkillGapSnapshotResource
    {
        // The route uses scoped bindings: the snapshot belongs to this project.
        $gate->authorize('view', $project);

        $skillGapSnapshot->load([
            'results',
            'competencySnapshot:id,project_id,status,created_at',
            'sourceSnapshot:id,project_id,version,file_count,primary_language,created_at',
        ]);

        return new SkillGapSnapshotResource($skillGapSnapshot);
    }
}
