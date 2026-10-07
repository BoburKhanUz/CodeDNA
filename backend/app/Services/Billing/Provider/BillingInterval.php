<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

enum BillingInterval: string
{
    case Monthly = 'MONTHLY';
    case Annual = 'ANNUAL';
}
