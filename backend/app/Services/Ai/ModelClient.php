<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\AiProviderResponse;

/**
 * A model runtime behind the AI gateway (Phase 29): a local Ollama server,
 * any OpenAI-compatible endpoint, or the offline fake. One call = one
 * attempt; retries are the caller's decision. A client returns the raw
 * response text and never validates or repairs it.
 */
interface ModelClient
{
    /** Stable provider identifier stored with each result, e.g. "ollama". */
    public function name(): string;

    /** The configured model identifier stored with each result. */
    public function model(): string;

    /**
     * @throws AiProviderException normalized: retryable or permanent, with a safe code
     */
    public function complete(AiRequest $request): AiProviderResponse;

    /**
     * Whether the runtime answers and has the configured model. Cheap: never
     * a generation.
     */
    public function health(): ModelHealth;
}
