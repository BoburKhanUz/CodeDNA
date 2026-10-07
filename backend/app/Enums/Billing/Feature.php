<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Product capabilities a plan can include (Phase 23,
 * docs/billing/entitlements-and-quotas.md). A closed set: a plan includes a
 * feature or it does not. Access is decided only by the billing domain
 * (App\Services\Billing\Entitlements), never by a plan's name.
 */
enum Feature: string
{
    case Projects = 'PROJECTS';
    case SourceAnalysis = 'SOURCE_ANALYSIS';
    case AiAssessment = 'AI_ASSESSMENT';
    case CodingChallenges = 'CODING_CHALLENGES';
    case LearningRoadmap = 'LEARNING_ROADMAP';
    case GrowthAnalytics = 'GROWTH_ANALYTICS';
    case HistoricalDna = 'HISTORICAL_DNA';
    case GitHubIntegration = 'GITHUB_INTEGRATION';

    public function label(): string
    {
        return match ($this) {
            self::Projects => 'Projects and source uploads',
            self::SourceAnalysis => 'Source analysis',
            self::AiAssessment => 'AI assessment',
            self::CodingChallenges => 'Coding challenges',
            self::LearningRoadmap => 'Learning roadmap',
            self::GrowthAnalytics => 'Growth analytics',
            self::HistoricalDna => 'Historical DNA',
            self::GitHubIntegration => 'GitHub integration',
        };
    }
}
