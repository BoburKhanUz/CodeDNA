<?php

declare(strict_types=1);

namespace App\Enums\Insights;

use App\Enums\Assessment\AssessmentFailure;

/**
 * Why an insight failed. Messages are fixed and safe to show: never model
 * responses, prompts, URLs or secrets. Provider codes are shared with
 * assessments (one gateway, one vocabulary).
 */
enum InsightFailure: string
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
    case Stale = 'INSIGHT_STALE';
    case InsightFailed = 'INSIGHT_FAILED';

    public static function fromAssessment(AssessmentFailure $failure): self
    {
        return self::tryFrom($failure->value) ?? self::InsightFailed;
    }

    public function message(): string
    {
        return match ($this) {
            self::ProviderTimeout => 'The local AI model did not respond in time.',
            self::ProviderRateLimited => 'The AI service is rate limiting requests.',
            self::ProviderUnavailable => 'The local AI service is unavailable or busy.',
            self::ProviderAuthFailed => 'The AI service rejected the configured credentials.',
            self::ProviderRejected => 'The AI service rejected the request (for example, the configured model is not installed).',
            self::OutputTooLarge => 'The AI response exceeded the allowed size.',
            self::InvalidOutput => 'The AI response did not meet the evidence rules and was discarded.',
            self::InputTooLarge => 'The evidence does not fit the configured AI context.',
            self::EvidenceChanged => 'The evidence changed after the insight was requested.',
            self::EvidenceInvalid => 'The stored evidence is not compatible with this insight version.',
            self::Stale => 'The insight did not complete in time.',
            self::InsightFailed => 'The insight could not be completed.',
        };
    }
}
