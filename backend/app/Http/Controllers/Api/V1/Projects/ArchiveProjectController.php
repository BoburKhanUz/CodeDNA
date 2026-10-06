<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\ArchiveProject;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * POST /api/v1/projects/{project}/archive — ACTIVE -> ARCHIVED (idempotent).
 */
final class ArchiveProjectController extends Controller
{
    public function __invoke(Project $project, Gate $gate, ArchiveProject $archiveProject): ProjectResource
    {
        $gate->authorize('archive', $project);

        return new ProjectResource($archiveProject->handle($project));
    }
}
