<?php

declare(strict_types=1);

namespace App\Services\Billing\Catalog;

use App\Enums\Billing\Feature;
use App\Enums\Billing\PlanStatus;
use App\Enums\Billing\QuotaKey;
use InvalidArgumentException;

/**
 * One plan version as defined in code. Stored once as an immutable plan row.
 */
final readonly class PlanDefinition
{
    /**
     * @param  list<Feature>  $features
     * @param  array<string, int|null>  $quotas  QuotaKey value => limit (null: unlimited)
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $name,
        public PlanStatus $status,
        public string $currency,
        public ?int $monthlyPriceMinor,
        public ?int $annualPriceMinor,
        public array $features,
        public array $quotas,
        public string $description,
    ) {
        if (preg_match('/^[A-Z][A-Z_]{1,31}$/D', $key) !== 1 || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Invalid plan definition identity.');
        }
        if (array_keys($quotas) !== array_map(fn (QuotaKey $q): string => $q->value, QuotaKey::cases())) {
            throw new InvalidArgumentException('A plan defines every quota, in catalog order.');
        }
        foreach ([$monthlyPriceMinor, $annualPriceMinor, ...array_values($quotas)] as $amount) {
            if ($amount !== null && $amount < 0) {
                throw new InvalidArgumentException('Prices and limits are never negative.');
            }
        }
        if (($status === PlanStatus::Reserved) !== ($monthlyPriceMinor === null)) {
            throw new InvalidArgumentException('Only a reserved plan has no price.');
        }
    }

    /** SHA-256 of the canonical definition (pins the catalog against silent edits). */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'key' => $this->key, 'version' => $this->version, 'name' => $this->name, 'status' => $this->status->value,
            'currency' => $this->currency, 'monthly' => $this->monthlyPriceMinor, 'annual' => $this->annualPriceMinor,
            'features' => array_map(fn (Feature $f): string => $f->value, $this->features), 'quotas' => $this->quotas,
            'description' => $this->description,
        ], JSON_THROW_ON_ERROR));
    }
}
