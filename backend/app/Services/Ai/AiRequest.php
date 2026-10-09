<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Assessment\AssessmentPrompt;

/**
 * One generation request to a model (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#ai-gateway): server-owned system
 * instructions, a user message that frames untrusted evidence JSON between
 * fixed delimiters, and the JSON schema the answer must follow. Nothing in
 * it comes from a request body; the task names what it is for (logs and
 * metrics only).
 */
final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $task,
        public string $system,
        public string $user,
        public string $schemaName,
        public array $schema,
        public int $maxOutputTokens,
    ) {}

    public static function fromAssessmentPrompt(AssessmentPrompt $prompt, int $maxOutputTokens): self
    {
        return new self('assessment', $prompt->system, $prompt->user, $prompt->schemaName, $prompt->schema, $maxOutputTokens);
    }

    /**
     * A conservative token estimate for the prompt (about three bytes per
     * token for English text and JSON; the schema is sent too). Used only to
     * refuse requests that cannot fit the model's context window.
     */
    public function estimatedPromptTokens(): int
    {
        $bytes = strlen($this->system) + strlen($this->user) + strlen((string) json_encode($this->schema));

        return (int) ceil($bytes / 3) + 64;
    }
}
