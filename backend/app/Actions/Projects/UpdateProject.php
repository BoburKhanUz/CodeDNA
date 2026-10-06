<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Applies validated changes to a project's descriptive fields. Archived
 * projects are read-only; status changes only through ArchiveProject.
 */
final readonly class UpdateProject
{
    /**
     * @param  array<string, mixed>  $changes  validated by UpdateProjectRequest
     */
    public function handle(Project $project, array $changes): Project
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }

        try {
            $project->fill($changes)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => ['You already have a project with this slug.']]);
        }

        return $project;
    }
}
