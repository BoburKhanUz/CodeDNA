<?php

declare(strict_types=1);

namespace App\Services\Assessment\Provider;

/**
 * The raw result of one provider call. $content is untrusted until the
 * response validator accepts it. Metadata is limited to fields that are
 * safe to store: no headers, no prompt, no request body.
 */
final readonly class AiProviderResponse
{
    public function __construct(
        public string $content,
        public ?string $servedModel = null,
        public ?string $responseId = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function metadata(): array
    {
        $safe = fn (?string $value): ?string => $value !== null && preg_match('/^[A-Za-z0-9._:\/-]{1,128}$/', $value) === 1 ? $value : null;

        return [
            'served_model' => $safe($this->servedModel),
            'response_id' => $safe($this->responseId),
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}
