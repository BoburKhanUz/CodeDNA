<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates a project owned by the user. New projects are ACTIVE.
 */
final readonly class CreateProject
{
    /**
     * @param  array<string, mixed>  $attributes  validated by StoreProjectRequest
     */
    public function handle(User $owner, array $attributes): Project
    {
        try {
            $project = $owner->projects()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request took the slug after validation passed.
            throw ValidationException::withMessages(['slug' => ['You already have a project with this slug.']]);
        }

        Log::info('Project created.', ['project_id' => $project->id, 'user_id' => $owner->id]);

        return $project->refresh();
    }
}
