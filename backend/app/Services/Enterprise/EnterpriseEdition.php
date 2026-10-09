<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

/**
 * The installation's edition (Phase 27, docs/enterprise/enterprise-architecture.md):
 * the only place that reads and verifies the license, and the only place the
 * rest of the application asks about enterprise entitlements.
 *
 * Community unless a license verifies as VALID right now. Bound as a scoped
 * service: the license is read once per request or queued job, so a renewed
 * (or removed) license takes effect without a restart and a long-running
 * worker never keeps an expired one.
 *
 * Fails closed: any problem reading or verifying the license means no
 * enterprise entitlement. The license's contents are never logged; only its
 * status and, once its signature is verified, its id.
 */
final class EnterpriseEdition
{
    private ?LicenseVerification $verification = null;

    public function __construct(
        private readonly Repository $config,
        private readonly LicenseVerifier $verifier,
    ) {}

    public function verification(): LicenseVerification
    {
        return $this->verification ??= $this->verify();
    }

    public function isEnterprise(): bool
    {
        return $this->verification()->granting() !== null;
    }

    /** The plan key a valid license puts organizations on, or null (their billing account decides). */
    public function organizationPlan(): ?string
    {
        return $this->verification()->granting()?->organizationPlan;
    }

    /** The seat limit a valid license grants every organization, or null. */
    public function organizationSeats(): ?int
    {
        return $this->verification()->granting()?->organizationSeats;
    }

    private function verify(): LicenseVerification
    {
        $path = (string) $this->config->get('codedna.enterprise.license_path', '');
        if ($path === '') {
            return new LicenseVerification(LicenseStatus::Absent);
        }
        $document = $this->read($path, (int) $this->config->get('codedna.enterprise.license_max_bytes', 16384));
        $verification = $document === null
            ? new LicenseVerification(LicenseStatus::Unreadable)
            : $this->verifier->verify(
                $document,
                (array) $this->config->get('license.trusted_keys', []),
                (string) parse_url((string) $this->config->get('app.url'), PHP_URL_HOST),
                CarbonImmutable::now('UTC'),
            );

        if ($verification->status !== LicenseStatus::Absent && ! $verification->status->grants()) {
            Log::warning('license.not_granting', [
                'status' => $verification->status->value,
                'license_id' => $verification->license?->id,
                'expires_at' => $verification->license?->expiresAt->toIso8601ZuluString(),
            ]);
        }

        return $verification;
    }

    /** The file's contents, or null when it cannot be read or exceeds the limit. */
    private function read(string $path, int $maxBytes): ?string
    {
        // The production default mounts /dev/null here: readable, empty, so ABSENT.
        if (! str_starts_with($path, '/') || ! file_exists($path) || is_dir($path) || ! is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path, false, null, 0, $maxBytes + 1);

        return is_string($contents) && strlen($contents) <= $maxBytes ? $contents : null;
    }
}
