<?php

declare(strict_types=1);

namespace App\Services\Assessment\Provider;

use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;

/**
 * A model provider behind the assessment pipeline
 * (docs/architecture/ai-assessment-v1.md#providers). The domain depends on
 * this interface only: no SDK, no provider-specific type. One call = one
 * attempt; retries are the job's decision.
 *
 * A provider returns the raw response text. It never interprets, repairs or
 * validates the content; App\Services\Assessment\AssessmentResponseValidator
 * does that for every provider alike.
 */
interface AiProvider
{
    /** Stable provider identifier stored with each assessment, e.g. "openai_compatible". */
    public function name(): string;

    /** The configured model identifier stored with each assessment. */
    public function model(): string;

    /**
     * @throws AiProviderException
     */
    public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse;
}
