<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use Illuminate\Support\Carbon;

/** A provider-hosted checkout the user would be sent to (no card data ever reaches CodeDNA). */
final readonly class CheckoutSession
{
    public function __construct(public string $id, public string $url, public Carbon $expiresAt) {}
}
