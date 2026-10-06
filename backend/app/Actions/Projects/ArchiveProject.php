<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * ACTIVE -> ARCHIVED. Irreversible for now. Archived projects keep their
 * full history (snapshots, runs, DNA) but accept no edits and no new source
 * snapshots. Archiving an archived project changes nothing (idempotent).
 *
 * This is the only way a project leaves the active list: there is no
 * delete, because history is protected by RESTRICT foreign keys and removal
 * needs a deliberate purge workflow (stored objects included).
 */
final readonly class ArchiveProject
{
    public function handle(Project $project): Project
    {
        if ($project->isActive()) {
            $project->archive();
            Log::info('Project archived.', ['project_id' => $project->id, 'user_id' => $project->user_id]);
        }

        return $project;
    }
}
