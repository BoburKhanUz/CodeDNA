<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * What a provider receives for one assessment: the server-owned system
 * instructions, the user message (fixed framing around the untrusted
 * evidence JSON) and the response schema. Built only from the
 * specification and the input; there is no user-supplied prompt.
 */
final readonly class AssessmentPrompt
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $version,
        public string $fingerprint,
        public string $system,
        public string $user,
        public string $schemaName,
        public array $schema,
    ) {}

    public static function for(AssessmentSpecification $spec, AssessmentInput $input): self
    {
        return new self(
            version: AssessmentSpecification::PROMPT_VERSION,
            fingerprint: $spec->promptFingerprint(),
            system: $spec->systemPrompt(),
            user: $spec->userMessage($input),
            schemaName: 'codedna_assessment_v1',
            schema: $spec->providerSchema(),
        );
    }
}
