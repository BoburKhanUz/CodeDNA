<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Product-level usage quotas (Phase 23). These are commercial limits on top
 * of, and independent from, the technical safety limits in config/codedna.php
 * (archive size, rate limits, attempts per challenge), which still apply to
 * every plan.
 */
enum QuotaKey: string
{
    case ActiveProjects = 'ACTIVE_PROJECTS';
    case SourceUploads = 'SOURCE_UPLOADS';
    case SourceUploadBytes = 'SOURCE_UPLOAD_BYTES';
    case Analyses = 'ANALYSES';
    case AiAssessments = 'AI_ASSESSMENTS';
    case ChallengeSubmissions = 'CHALLENGE_SUBMISSIONS';
    case GitHubImports = 'GITHUB_IMPORTS';

    public function period(): QuotaPeriod
    {
        return $this === self::ActiveProjects ? QuotaPeriod::Current : QuotaPeriod::Monthly;
    }

    /** The feature the quota belongs to. */
    public function feature(): Feature
    {
        return match ($this) {
            self::ActiveProjects, self::SourceUploads, self::SourceUploadBytes => Feature::Projects,
            self::Analyses => Feature::SourceAnalysis,
            self::AiAssessments => Feature::AiAssessment,
            self::ChallengeSubmissions => Feature::CodingChallenges,
            self::GitHubImports => Feature::GitHubIntegration,
        };
    }

    /** COUNT or BYTES. */
    public function unit(): string
    {
        return $this === self::SourceUploadBytes ? 'BYTES' : 'COUNT';
    }

    public function label(): string
    {
        return match ($this) {
            self::ActiveProjects => 'Active projects',
            self::SourceUploads => 'Source uploads',
            self::SourceUploadBytes => 'Uploaded source',
            self::Analyses => 'Analyses',
            self::AiAssessments => 'AI assessments',
            self::ChallengeSubmissions => 'Challenge submissions',
            self::GitHubImports => 'GitHub imports',
        };
    }
}
