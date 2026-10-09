<?php

declare(strict_types=1);

namespace App\Console\Commands\Enterprise;

use App\Services\Enterprise\EnterpriseEdition;
use App\Services\Enterprise\LicenseStatus;
use Illuminate\Console\Command;

/**
 * php artisan codedna:license (Phase 27, docs/enterprise/licensing.md#install):
 * the installation's edition and its license status, for operators. Prints
 * the verified claims, never the document or its signature. Exits 0 when
 * the license grants (or none is configured), 1 when one is configured but
 * does not grant, so a deployment script can stop on a bad renewal.
 */
final class LicenseStatusCommand extends Command
{
    protected $signature = 'codedna:license';

    protected $description = 'Show the edition and the enterprise license status';

    public function handle(EnterpriseEdition $edition): int
    {
        $verification = $edition->verification();
        $license = $verification->license;
        $this->line('Edition: '.($edition->isEnterprise() ? 'Enterprise' : 'Community'));
        $this->line('License: '.$verification->status->value);
        if ($license !== null) {
            $this->table(['Claim', 'Value'], [
                ['License ID', $license->id],
                ['Licensee', $license->licensee],
                ['Signing key', $license->keyId],
                ['Installation host', $license->installationHost],
                ['Valid from', $license->notBefore->toIso8601ZuluString()],
                ['Expires', $license->expiresAt->toIso8601ZuluString()],
                ['Organization plan', $license->organizationPlan],
                ['Organization seats', (string) $license->organizationSeats],
            ]);
        }
        if (! in_array($verification->status, [LicenseStatus::Valid, LicenseStatus::Absent], true)) {
            $this->warn('The license grants nothing: the installation runs as the Community edition (docs/enterprise/troubleshooting.md#license).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
