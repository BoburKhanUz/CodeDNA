<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Why an assessment failed. Messages are fixed and safe to show: never
 * provider responses, prompts, URLs or secrets.
 */
enum AssessmentFailure: string
{
    case ProviderTimeout = 'PROVIDER_TIMEOUT';
    case ProviderRateLimited = 'PROVIDER_RATE_LIMITED';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    case ProviderAuthFailed = 'PROVIDER_AUTH_FAILED';
    case ProviderRejected = 'PROVIDER_REJECTED';
    case OutputTooLarge = 'OUTPUT_TOO_LARGE';
    case InvalidOutput = 'INVALID_OUTPUT';
    case InputTooLarge = 'INPUT_TOO_LARGE';
    case EvidenceChanged = 'EVIDENCE_CHANGED';
    case EvidenceInvalid = 'EVIDENCE_INVALID';
    case Stale = 'ASSESSMENT_STALE';
    case AssessmentFailed = 'ASSESSMENT_FAILED';

    public function message(): string
    {
        return match ($this) {
            self::ProviderTimeout => 'The AI provider did not respond in time.',
            self::ProviderRateLimited => 'The AI provider is rate limiting requests.',
            self::ProviderUnavailable => 'The AI provider is unavailable.',
            self::ProviderAuthFailed => 'The AI provider rejected the configured credentials.',
            self::ProviderRejected => 'The AI provider rejected the request.',
            self::OutputTooLarge => 'The AI response exceeded the allowed size.',
            self::InvalidOutput => 'The AI response did not meet the assessment rules and was discarded.',
            self::InputTooLarge => 'The evidence exceeds the allowed input size.',
            self::EvidenceChanged => 'The evidence no longer matches the requested assessment.',
            self::EvidenceInvalid => 'The stored evidence is not compatible with this assessment version.',
            self::Stale => 'The assessment did not complete in time.',
            self::AssessmentFailed => 'The assessment could not be completed.',
        };
    }
}
