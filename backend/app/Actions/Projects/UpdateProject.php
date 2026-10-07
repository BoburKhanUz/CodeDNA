<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies validated changes to a project's descriptive fields. Archived
 * projects are read-only; status changes only through ArchiveProject.
 *
 * The status is checked on the locked row (Phase 21): an edit racing an
 * archive either completes before it or is refused after it, never lands
 * on an archived project.
 */
final readonly class UpdateProject
{
    /**
     * @param  array<string, mixed>  $changes  validated by UpdateProjectRequest
     */
    public function handle(Project $project, array $changes): Project
    {
        try {
            $locked = DB::transaction(static function () use ($project, $changes): Project {
                $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->isActive()) {
                    throw new ApiException(ErrorCode::ProjectArchived);
                }
                $locked->fill($changes)->save();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => ['You already have a project with this slug.']]);
        }

        return $project->setRawAttributes($locked->getAttributes(), true);
    }
}
