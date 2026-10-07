<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Projects are owner-only. A non-owner gets 404 (not 403), so the API never
 * reveals that another user's project exists. Project state rules (archived,
 * source type) are enforced by the actions, with their own error codes.
 */
final class ProjectPolicy
{
    public function view(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function update(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function archive(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function uploadSource(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function analyze(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function assess(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function practice(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    public function plan(User $actor, Project $project): Response
    {
        return $this->owner($actor, $project);
    }

    private function owner(User $actor, Project $project): Response
    {
        return $actor->getKey() === $project->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
