<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\QuotaKey;
use App\Exceptions\DomainRuleViolation;
use App\Models\BillingCustomer;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\BillingUsageEvent;
use App\Models\User;
use App\Services\Billing\UsageService;
use App\Support\ConfigurationValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

/**
 * Phase 23: billing history is append-only, and there is no way around the
 * billing domain: no configuration grants a plan, and the test provider is
 * refused wherever real users are.
 */
final class BillingRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_history_and_usage_are_append_only(): void
    {
        $user = User::factory()->create();
        BillingFixtures::subscribe($user);
        DB::transaction(fn () => app(UsageService::class)->consume($user, QuotaKey::Analyses, 'analysis_run', strtolower((string) Str::ulid())));

        foreach ([BillingCustomer::query()->sole(), BillingSubscriptionEvent::query()->sole(), BillingUsageEvent::query()->sole()] as $record) {
            $this->assertThrows(fn () => $record->forceFill(['created_at' => now()->subYear()])->save(), DomainRuleViolation::class);
            $this->assertThrows(fn () => $record->delete(), DomainRuleViolation::class);
        }
        foreach ([
            fn () => DB::table('billing_customers')->update(['provider_customer_ref' => 'cus_hijacked']),
            fn () => DB::table('billing_subscription_events')->update(['to_status' => 'ACTIVE']),
            fn () => DB::table('billing_usage_events')->update(['amount' => 0]),
            fn () => DB::table('billing_subscriptions')->update(['user_id' => User::factory()->create()->id]),
            fn () => DB::table('billing_subscriptions')->update(['provider_event_at' => now()->subYear()]),
        ] as $tamper) {
            $this->assertThrows(fn () => DB::transaction($tamper), QueryException::class);
        }
        $this->assertThrows(fn () => BillingSubscription::query()->sole()->delete(), DomainRuleViolation::class);
    }

    public function test_usage_counters_never_go_negative(): void
    {
        $user = User::factory()->create();
        DB::table('billing_usage_counters')->insert(['user_id' => $user->id, 'quota_key' => 'ANALYSES', 'period_start' => now()->startOfMonth(), 'used' => 0, 'updated_at' => now()]);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('billing_usage_counters')->update(['used' => -1])), QueryException::class);
    }

    public function test_no_configuration_can_grant_a_plan_or_raise_a_quota(): void
    {
        $this->assertSame(['provider', 'webhook_secret', 'webhook_tolerance_seconds', 'webhook_max_bytes'], array_keys((array) config('codedna.billing')));
        // The only billing environment variables are the provider and its webhook secret.
        preg_match_all("/env\\('([A-Z_]*(?:BILLING|PLAN|QUOTA|FORCE|UNLIMITED|ENTITLE)[A-Z_]*)'/", (string) file_get_contents(config_path('codedna.php')), $matches);
        $this->assertSame(['BILLING_PROVIDER', 'BILLING_WEBHOOK_SECRET'], $matches[1]);
    }

    public function test_the_fake_provider_is_refused_in_every_deployed_environment(): void
    {
        $config = clone config();
        $config->set('codedna.billing', ['provider' => 'fake', 'webhook_secret' => str_repeat('w', 40)]);
        foreach (['production', 'staging', 'prod', 'demo'] as $environment) {
            $this->assertContains('BILLING_PROVIDER "fake" is not allowed in production.', (new ConfigurationValidator)->problems($config, $environment));
        }
        $this->assertNotContains('BILLING_PROVIDER "fake" is not allowed in production.', (new ConfigurationValidator)->problems($config, 'local'));
    }
}
