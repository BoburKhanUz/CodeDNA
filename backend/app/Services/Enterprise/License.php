<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use Carbon\CarbonImmutable;

/**
 * The claims of a license whose signature has been verified (Phase 27,
 * docs/enterprise/licensing.md#format). Built only by LicenseVerifier.
 */
final readonly class License
{
    public function __construct(
        public string $id,
        public string $licensee,
        public string $keyId,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $expiresAt,
        public string $installationHost,
        public string $organizationPlan,
        public int $organizationSeats,
    ) {}
}
