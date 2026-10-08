<?php

declare(strict_types=1);

namespace App\Actions\Assessment;

use App\Enums\Assessment\AssessmentFailure;
use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Jobs\GenerateAssessment;
use App\Models\AiAssessment;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Assessment\AssessmentException;
use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentInputBuilder;
use App\Services\Assessment\AssessmentSpecification;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Billing\Entitlements;
use App\Services\Billing\UsageService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Requests an AI interpretation of a skill gap snapshot of a project
 * (docs/architecture/ai-assessment-v1.md#lifecycle). Never calls a
 * provider: it builds the input, records a QUEUED assessment and
 * dispatches App\Jobs\GenerateAssessment after the transaction commits.
 *
 * Identity: (project, input fingerprint, assessment version, prompt
 * fingerprint, provider, model).
 * - An assessment with the same identity that is QUEUED, RUNNING or
 *   SUCCEEDED is returned as is: repeating a request never causes a second
 *   provider call.
 * - After a FAILED one, a request creates a new assessment; the failed one
 *   stays as history.
 *
 * Concurrency: the project row is locked for the decision; the partial
 * unique index on active identities is the database backstop.
 */
final readonly class RequestAssessment
{
    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $dispatcher,
        private Repository $config,
        private Container $container,
        private AssessmentInputBuilder $builder,
        private Entitlements $entitlements,
        private UsageService $usage,
    ) {}

    public function handle(Project $project, User $actor, ?string $skillGapSnapshotId): RequestedAssessment
    {
        if ($this->config->get('codedna.ai.enabled') !== true) {
            throw new ApiException(ErrorCode::AiAssessmentDisabled);
        }
        // Billing (Phase 23): the owner's plan must include AI assessment.
        $this->entitlements->require($project, Feature::AiAssessment);
        $spec = AssessmentSpecification::forVersion((string) $this->config->get('codedna.ai.version'));
        /** @var AiProvider $provider */
        $provider = $this->container->make(AiProvider::class);

        try {
            $requested = $this->db->transaction(fn (): RequestedAssessment => $this->resolve($project, $actor, $skillGapSnapshotId, $spec, $provider));
        } catch (UniqueConstraintViolationException) {
            // Only reachable if the lock was bypassed; the index kept one active assessment.
            throw new ApiException(ErrorCode::InternalError);
        }

        if ($requested->created) {
            $this->dispatch($requested->assessment);
        }

        return $requested;
    }

    private function resolve(Project $project, User $actor, ?string $skillGapSnapshotId, AssessmentSpecification $spec, AiProvider $provider): RequestedAssessment
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and cannot start new assessments.');
        }

        $gaps = $this->snapshot($locked, $skillGapSnapshotId);
        try {
            $input = $this->builder->build($gaps, $spec);
        } catch (AssessmentException) {
            throw new ApiException(ErrorCode::AssessmentEvidenceUnavailable, 'The stored analysis is not compatible with this assessment version.');
        }
        if (strlen($input->canonicalJson()) > (int) $this->config->get('codedna.ai.max_input_bytes')) {
            throw new ApiException(ErrorCode::AssessmentInputTooLarge);
        }

        $existing = AiAssessment::query()
            ->where('project_id', $locked->id)
            ->where('input_fingerprint', $input->fingerprint())
            ->where('assessment_version', $spec->version())
            ->where('prompt_fingerprint', $spec->promptFingerprint())
            ->where('provider', $provider->name())
            ->where('model', $provider->model())
            ->whereIn('status', [AssessmentStatus::Queued->value, AssessmentStatus::Running->value, AssessmentStatus::Succeeded->value])
            ->first();
        if ($existing !== null) {
            return new RequestedAssessment($existing, false);
        }

        return new RequestedAssessment($this->create($gaps, $input, $spec, $provider, $actor), true);
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
            ?? throw new ApiException(ErrorCode::AssessmentEvidenceUnavailable);
    }

    private function create(SkillGapSnapshot $gaps, AssessmentInput $input, AssessmentSpecification $spec, AiProvider $provider, User $actor): AiAssessment
    {
        $assessment = new AiAssessment;
        $assessment->forceFill([
            'user_id' => $gaps->user_id,
            'project_id' => $gaps->project_id,
            'skill_gap_snapshot_id' => $gaps->id,
            'competency_snapshot_id' => $gaps->competency_snapshot_id,
            'dna_snapshot_id' => $gaps->dna_snapshot_id,
            'analysis_run_id' => $gaps->analysis_run_id,
            'source_snapshot_id' => $gaps->source_snapshot_id,
            'assessment_version' => $spec->version(),
            'input_schema_version' => AssessmentSpecification::INPUT_SCHEMA_VERSION,
            'output_schema_version' => AssessmentSpecification::OUTPUT_SCHEMA_VERSION,
            'prompt_version' => AssessmentSpecification::PROMPT_VERSION,
            'prompt_fingerprint' => $spec->promptFingerprint(),
            'specification_fingerprint' => $spec->fingerprint(),
            'input_fingerprint' => $input->fingerprint(),
            'dna_scoring_version' => $gaps->dna_scoring_version,
            'competency_version' => $gaps->competency_version,
            'skill_gap_version' => $gaps->skill_gap_version,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'status' => AssessmentStatus::Queued,
            'attempts' => 0,
            'input' => $input->toStored(),
        ]);
        $assessment->save();
        // Billing (Phase 23): one AI assessment of the project's billing subject (refunded if it ends FAILED).
        $this->usage->consume(Project::query()->findOrFail($gaps->project_id), QuotaKey::AiAssessments, 'ai_assessment', $assessment->id);

        Log::info('assessment.queued', [
            'assessment_id' => $assessment->id,
            'project_id' => $assessment->project_id,
            'input_fingerprint' => $assessment->input_fingerprint,
            'provider' => $assessment->provider,
            'model' => $assessment->model,
            'status' => $assessment->status->value,
            'requested_by' => $actor->getKey(),
            'request_id' => Context::get('request_id'),
        ]);

        return $assessment;
    }

    private function dispatch(AiAssessment $assessment): void
    {
        try {
            $this->dispatcher->dispatch(new GenerateAssessment($assessment->id));
        } catch (Throwable $e) {
            $assessment->forceFill([
                'status' => AssessmentStatus::Failed,
                'failure_code' => AssessmentFailure::AssessmentFailed->value,
                'failure_detail' => 'dispatch_failed',
                'completed_at' => Carbon::now(),
            ])->save();
            Log::error('assessment.failed', [
                'assessment_id' => $assessment->id,
                'project_id' => $assessment->project_id,
                'error_code' => AssessmentFailure::AssessmentFailed->value,
                'exception' => $e::class,
            ]);
        }
    }
}
