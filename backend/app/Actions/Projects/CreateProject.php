<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\QuotaService;
use App\Services\Organizations\OrganizationAccess;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates a project. New projects are ACTIVE.
 *
 * - Personal (no organization): owned by the user; the user's plan must
 *   include PROJECTS and have an active-project slot left.
 * - Team (Phase 24): owned by the organization, with the user as its
 *   creator (user_id). The creator must be an ADMIN or OWNER of the ACTIVE
 *   organization, and the organization's plan (not the creator's) must
 *   have a slot; PROJECT_CREATED is recorded in its audit log.
 *
 * A project's scope is fixed at creation: personal projects never become
 * team projects, or the other way round.
 */
final readonly class CreateProject
{
    public function __construct(private QuotaService $quotas, private OrganizationAccess $access, private OrganizationAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by StoreProjectRequest
     */
    public function handle(User $owner, array $attributes, ?Organization $organization = null): Project
    {
        try {
            // Billing (Phase 23): the PROJECTS feature and a free active-project
            // slot, decided under the subject's row lock with the creation itself.
            $project = DB::transaction(function () use ($owner, $attributes, $organization): Project {
                if ($organization === null) {
                    $this->quotas->requireProjectSlot($owner);

                    return $owner->projects()->create($attributes);
                }

                $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
                $this->access->requireForChange($owner, $locked, OrganizationRole::Admin);
                $this->quotas->requireProjectSlot($locked);
                $project = $owner->projects()->make($attributes);
                $project->forceFill(['organization_id' => $locked->getKey()])->save();
                $this->audit->record($locked, $owner, OrganizationAuditAction::ProjectCreated, $project, ['name' => $project->name]);

                return $project;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request took the slug after validation passed.
            throw ValidationException::withMessages(['slug' => [$organization === null
                ? 'You already have a project with this slug.'
                : 'The organization already has a project with this slug.']]);
        }

        Log::info('Project created.', ['project_id' => $project->id, 'user_id' => $owner->id, 'organization_id' => $project->organization_id]);

        return $project->refresh();
    }
}
