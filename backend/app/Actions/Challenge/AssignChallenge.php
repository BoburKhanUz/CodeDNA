<?php

declare(strict_types=1);

namespace App\Actions\Challenge;

use App\Enums\Challenge\ChallengeStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\ChallengeDefinition;
use App\Models\ChallengeInstance;
use App\Models\Project;
use App\Models\SkillGapResult;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeDefinitionData;
use App\Services\Challenge\ChallengeSelectionException;
use App\Services\Challenge\ChallengeSelector;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Assigns a challenge for a skill gap snapshot of a project
 * (docs/architecture/coding-challenges-v1.md#assignment). Deterministic
 * (ChallengeSelector), idempotent, and read-only towards every analysis
 * table: it reads the stored gaps and never changes them. No AI.
 *
 * - An active challenge (ASSIGNED or EVALUATING) for the same snapshot and
 *   competency is returned instead of a new one; without a requested
 *   competency, the newest active challenge of the snapshot is.
 * - Otherwise the selector picks the next definition and a new challenge is
 *   created with its selection provenance.
 *
 * Concurrency: the project row is locked for the decision; the partial
 * unique index on active challenges per (project, snapshot, competency) and
 * the unique (snapshot, definition) key are the database backstop.
 */
final readonly class AssignChallenge
{
    public function __construct(
        private ConnectionInterface $db,
        private Repository $config,
        private ChallengeCatalog $catalog,
        private ChallengeSelector $selector,
    ) {}

    public function handle(Project $project, User $actor, ?string $skillGapSnapshotId, ?string $competencyKey): AssignedChallenge
    {
        if ($this->config->get('codedna.challenges.enabled') !== true) {
            throw new ApiException(ErrorCode::ChallengesDisabled);
        }

        try {
            return $this->db->transaction(fn (): AssignedChallenge => $this->resolve($project, $actor, $skillGapSnapshotId, $competencyKey));
        } catch (UniqueConstraintViolationException) {
            // Only reachable if the lock was bypassed; the indexes kept one active challenge.
            throw new ApiException(ErrorCode::InternalError);
        }
    }

    private function resolve(Project $project, User $actor, ?string $skillGapSnapshotId, ?string $competencyKey): AssignedChallenge
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and cannot receive new challenges.');
        }
        $gaps = $this->snapshot($locked, $skillGapSnapshotId);

        $active = ChallengeInstance::query()
            ->where('project_id', $locked->id)
            ->where('skill_gap_snapshot_id', $gaps->id)
            ->whereIn('status', ChallengeStatus::activeValues())
            ->when($competencyKey !== null, fn ($query) => $query->where('competency_key', $competencyKey))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        if ($active !== null) {
            return new AssignedChallenge($active, false);
        }

        $results = SkillGapResult::query()->where('skill_gap_snapshot_id', $gaps->id)->orderBy('position')->get()->map(fn (SkillGapResult $r): array => [
            'competency_key' => $r->competency_key,
            'status' => $r->status->value,
            'priority' => $r->priority?->value,
            'priority_capped' => $r->priority_capped,
            'raw_gap' => $r->raw_gap,
            'current_score' => $r->current_score,
            'target_score' => $r->target_score,
        ])->all();
        $assigned = ChallengeInstance::query()->where('skill_gap_snapshot_id', $gaps->id)->pluck('definition_key')->all();
        $passed = ChallengeInstance::query()->where('project_id', $locked->id)->where('status', ChallengeStatus::Passed->value)->pluck('definition_key')->all();

        try {
            $selection = $this->selector->select(array_values($results), $this->catalog, array_values($assigned), array_values($passed), $competencyKey);
        } catch (ChallengeSelectionException $e) {
            throw new ApiException($e->reason === ChallengeSelectionException::NO_ELIGIBLE_GAP ? ErrorCode::ChallengeNoEligibleGap : ErrorCode::ChallengeNoneAvailable);
        }

        $definition = $this->publish($selection->definition);
        $instance = new ChallengeInstance;
        $instance->forceFill([
            'user_id' => $gaps->user_id,
            'project_id' => $gaps->project_id,
            'skill_gap_snapshot_id' => $gaps->id,
            'competency_snapshot_id' => $gaps->competency_snapshot_id,
            'dna_snapshot_id' => $gaps->dna_snapshot_id,
            'analysis_run_id' => $gaps->analysis_run_id,
            'source_snapshot_id' => $gaps->source_snapshot_id,
            'challenge_definition_id' => $definition->id,
            'definition_key' => $definition->key,
            'definition_version' => $definition->version,
            'competency_key' => $selection->competencyKey,
            'difficulty' => $selection->definition->difficulty(),
            'language' => $definition->language,
            'selection_version' => ChallengeSelector::VERSION,
            'catalog_version' => $this->catalog->version(),
            'catalog_fingerprint' => $this->catalog->fingerprint(),
            'selection' => $selection->provenance,
            'status' => ChallengeStatus::Assigned,
            'max_attempts' => (int) $this->config->get('codedna.challenges.max_attempts'),
            'attempts_used' => 0,
        ]);
        $instance->save();

        Log::info('challenge.assigned', [
            'challenge_id' => $instance->id,
            'project_id' => $instance->project_id,
            'skill_gap_snapshot_id' => $instance->skill_gap_snapshot_id,
            'definition' => $instance->definition_key.'@'.$instance->definition_version,
            'competency_key' => $instance->competency_key,
            'rule' => $selection->provenance['rule'],
            'requested_by' => $actor->getKey(),
            'request_id' => Context::get('request_id'),
        ]);

        return new AssignedChallenge($instance, true);
    }

    private function snapshot(Project $project, ?string $skillGapSnapshotId): SkillGapSnapshot
    {
        $query = SkillGapSnapshot::query()->where('project_id', $project->id);
        if ($skillGapSnapshotId !== null) {
            // Same answer for "does not exist" and "belongs to another project or user".
            return $query->whereKey($skillGapSnapshotId)->first()
                ?? throw ValidationException::withMessages(['skill_gap_snapshot_id' => 'The selected skill gap snapshot is invalid.']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->first()
            ?? throw new ApiException(ErrorCode::ChallengeNoEligibleGap, 'This project has no skill gap analysis yet.');
    }

    /**
     * The stored copy of a catalog definition: created on first use, and
     * then always identical (a changed published definition is a bug, not
     * something to overwrite).
     */
    private function publish(ChallengeDefinitionData $data): ChallengeDefinition
    {
        $stored = ChallengeDefinition::query()->where('key', $data->key())->where('version', $data->version())->first();
        if ($stored === null) {
            $stored = new ChallengeDefinition;
            $stored->forceFill([
                'key' => $data->key(),
                'version' => $data->version(),
                'catalog_version' => $this->catalog->version(),
                'category' => $data->category()->value,
                'difficulty' => $data->difficulty()->value,
                'language' => $data->language(),
                'runtime' => $data->document['runtime'],
                'title' => $data->document['title'],
                'definition_fingerprint' => $data->fingerprint(),
                'test_suite_fingerprint' => $data->testSuiteFingerprint(),
                'document' => $data->document,
            ]);
            try {
                // A savepoint: another project may publish the same definition at the same moment.
                $this->db->transaction(fn () => $stored->save());
            } catch (UniqueConstraintViolationException) {
                $stored = ChallengeDefinition::query()->where('key', $data->key())->where('version', $data->version())->firstOrFail();
            }
        }
        if ($stored->definition_fingerprint !== $data->fingerprint() || $stored->test_suite_fingerprint !== $data->testSuiteFingerprint()) {
            throw new RuntimeException("Published challenge definition {$data->key()}@{$data->version()} was changed; publish a new version instead.");
        }

        return $stored;
    }
}
