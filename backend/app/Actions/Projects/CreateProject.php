<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Services\Billing\QuotaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates a project owned by the user. New projects are ACTIVE. The
 * owner's plan must include PROJECTS and have an active-project slot left.
 */
final readonly class CreateProject
{
    public function __construct(private QuotaService $quotas) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by StoreProjectRequest
     */
    public function handle(User $owner, array $attributes): Project
    {
        try {
            // Billing (Phase 23): the PROJECTS feature and a free active-project
            // slot, decided under the user's row lock with the creation itself.
            $project = DB::transaction(function () use ($owner, $attributes): Project {
                $this->quotas->requireProjectSlot($owner);

                return $owner->projects()->create($attributes);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request took the slug after validation passed.
            throw ValidationException::withMessages(['slug' => ['You already have a project with this slug.']]);
        }

        Log::info('Project created.', ['project_id' => $project->id, 'user_id' => $owner->id]);

        return $project->refresh();
    }
}
