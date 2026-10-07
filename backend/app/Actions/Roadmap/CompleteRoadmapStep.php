<?php

declare(strict_types=1);

namespace App\Actions\Roadmap;

use App\Enums\Roadmap\RoadmapStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\RoadmapStep;
use App\Models\RoadmapStepCompletion;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Marks one step of a roadmap as completed
 * (docs/architecture/learning-roadmap-v1.md#progress). Self-reported
 * learning progress only: it never changes a score, a competency, a gap or
 * a priority, and it never starts an analysis.
 *
 * - Idempotent: completing a completed step returns the roadmap unchanged.
 * - Only an ACTIVE roadmap of an active project accepts progress.
 * - A step's prerequisites must be completed first.
 * - Completing the last step makes the roadmap COMPLETED.
 *
 * The project and roadmap rows are locked for the decision; the unique
 * step key of a completion is the database backstop.
 */
final readonly class CompleteRoadmapStep
{
    public function __construct(private ConnectionInterface $db) {}

    public function handle(Project $project, RoadmapSnapshot $roadmap, string $stepKey, User $actor): CompletedRoadmapStep
    {
        return $this->db->transaction(function () use ($project, $roadmap, $stepKey, $actor): CompletedRoadmapStep {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            if (! $lockedProject->isActive()) {
                throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and its roadmap progress cannot change.');
            }
            $locked = RoadmapSnapshot::query()->whereKey($roadmap->getKey())->lockForUpdate()->firstOrFail();
            $step = RoadmapStep::query()->where('roadmap_snapshot_id', $locked->id)->where('step_key', $stepKey)->first()
                ?? throw new ApiException(ErrorCode::ResourceNotFound);
            $completed = RoadmapStepCompletion::query()
                ->join('roadmap_steps', 'roadmap_steps.id', '=', 'roadmap_step_completions.roadmap_step_id')
                ->where('roadmap_step_completions.roadmap_snapshot_id', $locked->id)
                ->pluck('roadmap_steps.step_key')
                ->all();
            if (in_array($step->step_key, $completed, true)) {
                return new CompletedRoadmapStep($locked, false);
            }
            if ($locked->status !== RoadmapStatus::Active) {
                throw new ApiException(ErrorCode::RoadmapNotActive);
            }
            if (array_diff($step->prerequisites, $completed) !== []) {
                throw new ApiException(ErrorCode::RoadmapStepPrerequisitesIncomplete);
            }

            $completion = new RoadmapStepCompletion;
            $completion->forceFill([
                'roadmap_snapshot_id' => $locked->id,
                'roadmap_step_id' => $step->id,
                'project_id' => $locked->project_id,
                'user_id' => $locked->user_id,
                'completed_at' => Carbon::now(),
            ]);
            $completion->save();
            if (count($completed) + 1 === $locked->step_count) {
                $locked->forceFill(['status' => RoadmapStatus::Completed, 'completed_at' => Carbon::now()])->save();
            }

            Log::info('roadmap.step_completed', [
                'roadmap_id' => $locked->id,
                'project_id' => $locked->project_id,
                'step' => $step->step_key,
                'roadmap_status' => $locked->status->value,
                'requested_by' => $actor->getKey(),
                'request_id' => Context::get('request_id'),
            ]);

            return new CompletedRoadmapStep($locked, true);
        });
    }
}
