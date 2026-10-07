<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * How a quota is measured.
 *
 * - MONTHLY: consumption within the billing period (a paid subscription's
 *   current period, or the UTC calendar month on the free plan). A new
 *   period starts from zero.
 * - CURRENT: a gauge of what exists now (for example active projects).
 *   Nothing is consumed; archiving frees a unit.
 */
enum QuotaPeriod: string
{
    case Monthly = 'MONTHLY';
    case Current = 'CURRENT';
}
