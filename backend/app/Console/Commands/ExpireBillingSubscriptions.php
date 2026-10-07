<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ends subscriptions canceled at the end of their period once that period
 * is over (Phase 23). Access already stopped at the period end
 * (BillingSubscription::grantsAt); this records the terminal state and its
 * history. Scheduled hourly.
 */
final class ExpireBillingSubscriptions extends Command
{
    protected $signature = 'billing:expire-subscriptions';

    protected $description = 'Mark subscriptions canceled at period end as EXPIRED once the period is over';

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info('Subscriptions expired: '.$subscriptions->expireEnded(Carbon::now()));

        return self::SUCCESS;
    }
}
