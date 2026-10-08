<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Organizations\OrganizationAccess;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a project (docs/teams/authorization.md#projects).
 *
 * Personal projects (organization_id NULL) are owner-only. A non-owner
 * gets 404 (not 403), so the API never reveals that another user's project
 * exists.
 *
 * Organization projects (Phase 24) belong to the organization, not to
 * their creator: access needs an ACTIVE membership, decided by
 * OrganizationAccess. A non-member (or a removed member, the creator
 * included) gets 404; a suspended member 403 MEMBERSHIP_SUSPENDED.
 *
 * - view: any member, also while the organization is suspended or archived;
 * - contribute (upload, analyze, assess, practice, plan): any member, in an
 *   ACTIVE organization;
 * - manage (update, archive, connect a source provider): ADMIN or OWNER, in
 *   an ACTIVE organization.
 *
 * Project state rules (archived, source type) are enforced by the actions,
 * with their own error codes.
 */
final readonly class ProjectPolicy
{
    public function __construct(private OrganizationAccess $access) {}

    public function view(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, null);
    }

    public function update(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Admin);
    }

    public function archive(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Admin);
    }

    public function uploadSource(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Member);
    }

    public function analyze(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Member);
    }

    public function assess(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Member);
    }

    public function practice(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Member);
    }

    public function plan(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Member);
    }

    /** Connect, change or disconnect a source provider, and import from it (Phase 19). */
    public function connectSource(User $actor, Project $project): Response
    {
        return $this->decide($actor, $project, OrganizationRole::Admin);
    }

    /**
     * @param  OrganizationRole|null  $change  null for reads; otherwise the role a change needs
     *
     * @throws ApiException MEMBERSHIP_SUSPENDED, INSUFFICIENT_ORGANIZATION_ROLE, ORGANIZATION_SUSPENDED, ORGANIZATION_ARCHIVED
     */
    private function decide(User $actor, Project $project, ?OrganizationRole $change): Response
    {
        if ($project->organization_id === null) {
            return $actor->getKey() === $project->user_id ? Response::allow() : Response::denyAsNotFound();
        }
        $organization = Organization::query()->findOrFail($project->organization_id);
        $change === null
            ? $this->access->require($actor, $organization)
            : $this->access->requireForChange($actor, $organization, $change);

        return Response::allow();
    }
}
