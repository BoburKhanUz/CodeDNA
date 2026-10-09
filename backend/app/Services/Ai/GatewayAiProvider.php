<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\AiProviderResponse;

/**
 * The Phase 15 assessment pipeline on the Phase 29 gateway: the same
 * provider contract, now with the context budget, concurrency limit and
 * metrics of AiGateway, for every model client (Ollama or
 * OpenAI-compatible).
 */
final readonly class GatewayAiProvider implements AiProvider
{
    public function __construct(private AiGateway $gateway, private int $maxOutputTokens) {}

    public function name(): string
    {
        return $this->gateway->provider();
    }

    public function model(): string
    {
        return $this->gateway->model();
    }

    public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse
    {
        return $this->gateway->complete(AiRequest::fromAssessmentPrompt($prompt, $this->maxOutputTokens));
    }
}
