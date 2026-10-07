<?php

declare(strict_types=1);

namespace App\Services\Billing\Catalog;

use App\Enums\Billing\Feature;
use App\Enums\Billing\PlanStatus;
use App\Enums\Billing\QuotaKey;

/**
 * The plan catalog, version 1.0.0 (Phase 23, docs/billing/entitlements-and-quotas.md).
 *
 * FROZEN: the billing migration stores exactly these definitions as
 * immutable plan rows, and subscriptions point at those rows. A commercial
 * change is a new catalog version with new plan versions (a new class and a
 * migration), never an edit here; PlanCatalogTest pins the fingerprint.
 *
 * Money is in integer minor units of the plan's currency (USD cents). A
 * null price means "not offered" (a reserved plan). A null quota limit
 * means unlimited.
 */
final class PlanCatalogV1
{
    public const VERSION = '1.0.0';

    private const MIB = 1024 * 1024;

    /**
     * @return list<PlanDefinition>
     */
    public static function definitions(): array
    {
        $allFeatures = Feature::cases();
        $paid = [
            QuotaKey::ActiveProjects->value => 25,
            QuotaKey::SourceUploads->value => 500,
            QuotaKey::SourceUploadBytes->value => 10240 * self::MIB,
            QuotaKey::Analyses->value => 1000,
            QuotaKey::AiAssessments->value => 100,
            QuotaKey::ChallengeSubmissions->value => 1000,
            QuotaKey::GitHubImports->value => 300,
        ];

        return [
            new PlanDefinition(
                key: 'FREE',
                version: '1.0.0',
                name: 'Free',
                status: PlanStatus::Active,
                currency: 'USD',
                monthlyPriceMinor: 0,
                annualPriceMinor: 0,
                features: array_values(array_filter($allFeatures, fn (Feature $f): bool => $f !== Feature::AiAssessment)),
                quotas: [
                    QuotaKey::ActiveProjects->value => 3,
                    QuotaKey::SourceUploads->value => 30,
                    QuotaKey::SourceUploadBytes->value => 500 * self::MIB,
                    QuotaKey::Analyses->value => 60,
                    QuotaKey::AiAssessments->value => 0,
                    QuotaKey::ChallengeSubmissions->value => 60,
                    QuotaKey::GitHubImports->value => 20,
                ],
                description: 'Analyze a few projects and practice with challenges.',
            ),
            new PlanDefinition(
                key: 'PRO',
                version: '1.0.0',
                name: 'Pro',
                status: PlanStatus::Active,
                currency: 'USD',
                monthlyPriceMinor: 1500,
                annualPriceMinor: 15000,
                features: $allFeatures,
                quotas: $paid,
                description: 'More projects and analyses, and AI interpretation.',
            ),
            new PlanDefinition(
                key: 'TEAM_READY',
                version: '1.0.0',
                name: 'Team',
                status: PlanStatus::Reserved,
                currency: 'USD',
                monthlyPriceMinor: null,
                annualPriceMinor: null,
                features: $allFeatures,
                quotas: $paid,
                description: 'Reserved for a later release. Not offered yet.',
            ),
        ];
    }
}
