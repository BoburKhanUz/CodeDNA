<?php

declare(strict_types=1);

namespace App\Actions\Insights;

use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Enums\Insights\InsightFailure;
use App\Enums\Insights\InsightKind;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Jobs\GenerateInsight;
use App\Models\AiInsight;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiStatus;
use App\Services\Billing\Entitlements;
use App\Services\Billing\UsageService;
use App\Services\Insights\InsightException;
use App\Services\Insights\InsightInput;
use App\Services\Insights\InsightInputBuilder;
use App\Services\Insights\InsightSpecification;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Requests an AI insight about one deterministic result of a project
 * (Phase 29, docs/architecture/ai-intelligence-v1.md#lifecycle). Never calls
 * a model: it builds the input, records a QUEUED insight and dispatches
 * App\Jobs\GenerateInsight after the transaction commits.
 *
 * - The client names a kind and a subject ID only; the subject must belong
 *   to this project (otherwise 404, the same answer as "does not exist").
 * - AI disabled: AI_ASSESSMENT_DISABLED; the local runtime or model known to
 *   be unavailable (cached health check): AI_UNAVAILABLE. Nothing is
 *   recorded or charged then.
 * - The plan must include AI assessment, and one AI_ASSESSMENTS unit is
 *   consumed per new insight (refunded if it ends FAILED): local AI keeps
 *   the plans' semantics unchanged.
 * - Identity: (project, kind, input fingerprint, insight version, prompt
 *   fingerprint, provider, model). An active or succeeded insight with the
 *   same identity is returned instead of a new one; new evidence (a newer
 *   analysis, a completed roadmap step) is a new identity.
 */
final readonly class RequestInsight
{
    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $dispatcher,
        private Repository $config,
        private InsightInputBuilder $builder,
        private AiGateway $gateway,
        private AiStatus $status,
        private Entitlements $entitlements,
        private UsageService $usage,
    ) {}

    public function handle(Project $project, User $actor, InsightKind $kind, string $subjectId): RequestedInsight
    {
        if ($this->config->get('codedna.ai.enabled') !== true) {
            throw new ApiException(ErrorCode::AiAssessmentDisabled);
        }
        $this->entitlements->require($project, Feature::AiAssessment);
        if (! $this->status->available()) {
            throw new ApiException(ErrorCode::AiUnavailable);
        }
        $spec = InsightSpecification::forVersion((string) $this->config->get('codedna.ai.insights_version'));

        try {
            $requested = $this->db->transaction(fn (): RequestedInsight => $this->resolve($project, $actor, $kind, $subjectId, $spec));
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::InternalError);
        }
        if ($requested->created) {
            $this->dispatch($requested->insight);
        }

        return $requested;
    }

    private function resolve(Project $project, User $actor, InsightKind $kind, string $subjectId, InsightSpecification $spec): RequestedInsight
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and cannot start new AI insights.');
        }
        $subject = $this->builder->subject($kind, $locked->id, strtolower($subjectId))
            ?? throw new ApiException(ErrorCode::ResourceNotFound);

        $input = $this->input($kind, $subject, $spec);
        $existing = AiInsight::query()
            ->where('project_id', $locked->id)
            ->where('kind', $kind->value)
            ->where('input_fingerprint', $input->fingerprint())
            ->where('insight_version', $spec->version())
            ->where('prompt_fingerprint', $spec->promptFingerprint($kind))
            ->where('provider', $this->gateway->provider())
            ->where('model', $this->gateway->model())
            ->whereIn('status', [AssessmentStatus::Queued->value, AssessmentStatus::Running->value, AssessmentStatus::Succeeded->value])
            ->first();
        if ($existing !== null) {
            return new RequestedInsight($existing, false);
        }

        return new RequestedInsight($this->create($locked, $actor, $kind, $subject, $input, $spec), true);
    }

    private function input(InsightKind $kind, Model $subject, InsightSpecification $spec): InsightInput
    {
        try {
            $input = $this->builder->build($kind, $subject, $spec);
        } catch (InsightException $e) {
            throw $e->failure === InsightFailure::InputTooLarge
                ? new ApiException(ErrorCode::InsightInputTooLarge)
                : new ApiException(ErrorCode::InsightEvidenceUnavailable);
        }
        $request = new AiRequest(strtolower($kind->value), $spec->systemPrompt($kind), $spec->userMessage($input), 'codedna_insight_v1',
            $spec->providerSchema($input), (int) $this->config->get('codedna.ai.max_output_tokens'));
        if (strlen($input->canonicalJson()) > (int) $this->config->get('codedna.ai.max_input_bytes') || ! $this->gateway->fits($request)) {
            throw new ApiException(ErrorCode::InsightInputTooLarge);
        }

        return $input;
    }

    private function create(Project $project, User $actor, InsightKind $kind, Model $subject, InsightInput $input, InsightSpecification $spec): AiInsight
    {
        $insight = new AiInsight;
        $insight->forceFill([
            'user_id' => $subject->getAttribute('user_id'),
            'project_id' => $project->id,
            'kind' => $kind,
            $kind->subjectColumn() => $subject->getKey(),
            'requested_by' => $actor->getKey(),
            'insight_version' => $spec->version(),
            'input_schema_version' => InsightSpecification::INPUT_SCHEMA_VERSION,
            'output_schema_version' => InsightSpecification::OUTPUT_SCHEMA_VERSION,
            'prompt_version' => InsightSpecification::PROMPT_VERSION,
            'prompt_fingerprint' => $spec->promptFingerprint($kind),
            'specification_fingerprint' => $spec->fingerprint(),
            'input_fingerprint' => $input->fingerprint(),
            'provider' => $this->gateway->provider(),
            'model' => $this->gateway->model(),
            'status' => AssessmentStatus::Queued,
            'attempts' => 0,
            'input' => $input->toStored(),
        ]);
        $insight->save();
        // Billing (Phase 23): one AI assessment unit of the project's billing subject (refunded if it ends FAILED).
        $this->usage->consume($project, QuotaKey::AiAssessments, 'ai_insight', $insight->id);

        Log::info('insight.queued', [
            'insight_id' => $insight->id,
            'project_id' => $insight->project_id,
            'kind' => $kind->value,
            'input_fingerprint' => $insight->input_fingerprint,
            'provider' => $insight->provider,
            'model' => $insight->model,
            'requested_by' => $actor->getKey(),
            'request_id' => Context::get('request_id'),
        ]);

        return $insight;
    }

    private function dispatch(AiInsight $insight): void
    {
        try {
            $this->dispatcher->dispatch(new GenerateInsight($insight->id));
        } catch (Throwable $e) {
            $insight->forceFill([
                'status' => AssessmentStatus::Failed,
                'failure_code' => InsightFailure::InsightFailed->value,
                'failure_detail' => 'dispatch_failed',
                'completed_at' => Carbon::now(),
            ])->save();
            Log::error('insight.failed', ['insight_id' => $insight->id, 'project_id' => $insight->project_id, 'error_code' => InsightFailure::InsightFailed->value, 'exception' => $e::class]);
        }
    }
}
