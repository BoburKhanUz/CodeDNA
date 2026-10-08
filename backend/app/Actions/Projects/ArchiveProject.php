<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Models\Project;
use App\Models\User;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ACTIVE -> ARCHIVED. Irreversible for now. Archived projects keep their
 * full history (snapshots, runs, DNA) but accept no edits and no new source
 * snapshots. Archiving an archived project changes nothing (idempotent).
 * A team project's archiving is recorded in its organization's audit log.
 *
 * This is the only way a project leaves the active list: there is no
 * delete, because history is protected by RESTRICT foreign keys and removal
 * needs a deliberate purge workflow (stored objects included).
 */
final readonly class ArchiveProject
{
    public function __construct(private OrganizationAudit $audit) {}

    public function handle(Project $project, ?User $actor = null): Project
    {
        if ($project->isActive()) {
            DB::transaction(function () use ($project, $actor): void {
                $project->archive();
                if ($project->organization_id !== null) {
                    $this->audit->record($project->organization_id, $actor, OrganizationAuditAction::ProjectArchived, $project, ['name' => $project->name]);
                }
            });
            Log::info('Project archived.', ['project_id' => $project->id, 'user_id' => $project->user_id, 'organization_id' => $project->organization_id]);
        }

        return $project;
    }
}
