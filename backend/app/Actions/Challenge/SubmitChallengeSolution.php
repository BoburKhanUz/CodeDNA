<?php

declare(strict_types=1);

namespace App\Actions\Challenge;

use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Enums\Challenge\ChallengeStatus;
use App\Enums\Challenge\SubmissionFailure;
use App\Enums\Challenge\SubmissionStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeDefinition;
use App\Models\ChallengeInstance;
use App\Models\ChallengeSubmission;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\UsageService;
use App\Services\Challenge\ChallengeGrader;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Records one attempt at a challenge and queues its evaluation
 * (docs/architecture/coding-challenges-v1.md#submission). Never runs code:
 * evaluation happens in the isolated evaluator, from a queued job.
 *
 * - The challenge must be ASSIGNED (not EVALUATING, PASSED or FAILED), of an
 *   active project, with graded attempts left, and evaluation available.
 * - Every attempt is a new immutable row; the challenge becomes EVALUATING
 *   until its result arrives, so there is one pending attempt at a time.
 * - Idempotency-Key: a repeat with the same key and source returns the
 *   original submission; the same key with different source is refused.
 *
 * The challenge row is locked for the decision; the database checks the
 * attempt limit, the single pending attempt and the source hash again.
 */
final readonly class SubmitChallengeSolution
{
    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $dispatcher,
        private Repository $config,
        private ChallengeEvaluator $evaluator,
        private Entitlements $entitlements,
        private UsageService $usage,
    ) {}

    public function handle(ChallengeInstance $challenge, User $actor, string $language, string $source, ?string $idempotencyKey): SubmittedSolution
    {
        if ($this->config->get('codedna.challenges.enabled') !== true) {
            throw new ApiException(ErrorCode::ChallengesDisabled);
        }
        $keyHash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);
        $sourceHash = hash('sha256', $source);
        if ($keyHash !== null && ($replay = $this->replay($challenge, $keyHash, $sourceHash)) !== null) {
            return new SubmittedSolution($replay, false);
        }
        // Billing (Phase 23): the owner's plan must include coding challenges.
        $this->entitlements->require(Project::query()->findOrFail($challenge->project_id), Feature::CodingChallenges);
        if (! $this->evaluator->available()) {
            throw new ApiException(ErrorCode::ChallengeEvaluationUnavailable);
        }

        try {
            $submitted = $this->db->transaction(fn (): SubmittedSolution => $this->record($challenge, $actor, $language, $source, $sourceHash, $keyHash));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won: either the same Idempotency-Key or another pending attempt.
            if ($keyHash !== null && ($replay = $this->replay($challenge, $keyHash, $sourceHash)) !== null) {
                return new SubmittedSolution($replay, false);
            }
            throw new ApiException(ErrorCode::ChallengeEvaluationPending);
        }

        if ($submitted->created) {
            $this->dispatch($submitted->submission);
        }

        return $submitted;
    }

    private function record(ChallengeInstance $challenge, User $actor, string $language, string $source, string $sourceHash, ?string $keyHash): SubmittedSolution
    {
        $project = Project::query()->whereKey($challenge->project_id)->lockForUpdate()->firstOrFail();
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived, 'This project is archived and accepts no new attempts.');
        }
        $locked = ChallengeInstance::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
        if ($keyHash !== null && ($replay = $this->replay($locked, $keyHash, $sourceHash)) !== null) {
            return new SubmittedSolution($replay, false);
        }
        match ($locked->status) {
            ChallengeStatus::Evaluating => throw new ApiException(ErrorCode::ChallengeEvaluationPending),
            ChallengeStatus::Passed, ChallengeStatus::Failed => throw new ApiException(ErrorCode::ChallengeClosed),
            ChallengeStatus::Assigned => null,
        };
        if ($language !== $locked->language) {
            throw ValidationException::withMessages(['language' => 'This challenge is solved in '.$locked->language.'.']);
        }
        if ($locked->attempts_used >= $locked->max_attempts) {
            throw new ApiException(ErrorCode::ChallengeClosed);
        }

        /** @var ChallengeDefinition $definition */
        $definition = ChallengeDefinition::query()->findOrFail($locked->challenge_definition_id);
        $submission = new ChallengeSubmission;
        $submission->forceFill([
            'challenge_instance_id' => $locked->id,
            'project_id' => $locked->project_id,
            'user_id' => $locked->user_id,
            'challenge_definition_id' => $definition->id,
            'attempt_number' => (int) ChallengeSubmission::query()->where('challenge_instance_id', $locked->id)->max('attempt_number') + 1,
            'language' => $language,
            'source' => $source,
            'source_sha256' => $sourceHash,
            'source_bytes' => strlen($source),
            'idempotency_key_hash' => $keyHash,
            'status' => SubmissionStatus::Queued,
            'definition_fingerprint' => $definition->definition_fingerprint,
            'test_suite_fingerprint' => $definition->test_suite_fingerprint,
            'evaluation_version' => ChallengeGrader::VERSION,
            'evaluator' => $this->evaluator->name(),
        ]);
        $submission->save();
        // Billing (Phase 23): one submission of the owner's plan (refunded if
        // the evaluation ends in ERROR, which also uses no graded attempt).
        $this->usage->consume($project, QuotaKey::ChallengeSubmissions, 'challenge_submission', $submission->id);
        $locked->forceFill(['status' => ChallengeStatus::Evaluating])->save();

        Log::info('challenge.submitted', [
            'submission_id' => $submission->id,
            'challenge_id' => $locked->id,
            'project_id' => $locked->project_id,
            'attempt' => $submission->attempt_number,
            'source_bytes' => $submission->source_bytes,
            'requested_by' => $actor->getKey(),
            'request_id' => Context::get('request_id'),
        ]);

        return new SubmittedSolution($submission, true);
    }

    private function replay(ChallengeInstance $challenge, string $keyHash, string $sourceHash): ?ChallengeSubmission
    {
        $existing = ChallengeSubmission::query()
            ->where('challenge_instance_id', $challenge->id)
            ->where('idempotency_key_hash', $keyHash)
            ->first();
        if ($existing !== null && $existing->source_sha256 !== $sourceHash) {
            throw new ApiException(ErrorCode::IdempotencyKeyReused, 'This Idempotency-Key was already used for a different submission.');
        }

        return $existing;
    }

    private function dispatch(ChallengeSubmission $submission): void
    {
        try {
            $this->dispatcher->dispatch(new EvaluateChallengeSubmission($submission->id));
        } catch (Throwable $e) {
            $this->db->transaction(function () use ($submission): void {
                $locked = ChallengeSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill([
                    'status' => SubmissionStatus::Error,
                    'failure_code' => SubmissionFailure::EvaluationFailed->value,
                    'failure_detail' => 'dispatch_failed',
                    'completed_at' => Carbon::now(),
                ])->save();
                ChallengeInstance::query()->whereKey($locked->challenge_instance_id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['status' => ChallengeStatus::Assigned, 'last_result' => 'ERROR'])->save();
            });
            Log::error('challenge.evaluation_failed', ['submission_id' => $submission->id, 'error_code' => SubmissionFailure::EvaluationFailed->value, 'exception' => $e::class]);
        }
    }
}
