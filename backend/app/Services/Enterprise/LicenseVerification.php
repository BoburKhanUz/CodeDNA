<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

/**
 * A verification result: the status, and the license when its signature was
 * verified (also for an expired, not-yet-valid or other-installation license,
 * so operators can see what was installed). Grants only when VALID.
 */
final readonly class LicenseVerification
{
    public function __construct(
        public LicenseStatus $status,
        public ?License $license = null,
    ) {}

    /** The license, only when it grants its entitlements now. */
    public function granting(): ?License
    {
        return $this->status->grants() ? $this->license : null;
    }
}
