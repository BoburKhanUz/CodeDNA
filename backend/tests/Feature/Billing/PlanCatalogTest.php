<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Billing\Feature;
use App\Enums\Billing\PlanStatus;
use App\Enums\Billing\QuotaKey;
use App\Exceptions\DomainRuleViolation;
use App\Models\BillingPlan;
use App\Services\Billing\Catalog\PlanCatalogV1;
use App\Services\Billing\PlanCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 23: the plan catalog is server-owned, versioned and immutable.
 */
final class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINTS = [
        'FREE' => 'c08ade4e85191dd774ecac88ae47d34e5e332335a4f8d3515f3b0a1217ae7098',
        'PRO' => '9e454d0e32185352984682a33a2438d299bf52f8a3b33ed5fb3f7f75390109dc',
        'TEAM_READY' => '48886a652974256e94deb8b9a22f6a17f1e7ae862fabda3b10db30ee29786453',
    ];

    public function test_the_frozen_catalog_definitions_are_pinned(): void
    {
        // Changing a commercial term means a new catalog version, never an edit of 1.0.0.
        $fingerprints = [];
        foreach (PlanCatalogV1::definitions() as $plan) {
            $fingerprints[$plan->key] = $plan->fingerprint();
        }

        $this->assertSame(self::FINGERPRINTS, $fingerprints);
        $this->assertSame('1.0.0', PlanCatalogV1::VERSION);
    }

    public function test_the_stored_plans_are_exactly_the_catalog_definitions(): void
    {
        foreach (PlanCatalogV1::definitions() as $definition) {
            $plan = BillingPlan::query()->where('key', $definition->key)->where('version', $definition->version)->with(['features', 'quotas'])->sole();
            $this->assertSame([$definition->name, $definition->status, $definition->currency, $definition->monthlyPriceMinor, $definition->annualPriceMinor, $definition->fingerprint()],
                [$plan->name, $plan->status, $plan->currency, $plan->monthly_price_minor, $plan->annual_price_minor, $plan->fingerprint]);
            $this->assertEqualsCanonicalizing(array_map(fn (Feature $f): string => $f->value, $definition->features), $plan->features->map(fn ($f) => $f->feature->value)->all());
            foreach (QuotaKey::cases() as $key) {
                $this->assertSame($definition->quotas[$key->value], $plan->limitFor($key), "{$definition->key} {$key->value}");
            }
        }
        $this->assertSame(3, BillingPlan::query()->count());
    }

    public function test_the_free_plan_is_a_real_free_plan_without_ai(): void
    {
        $free = app(PlanCatalog::class)->free();

        $this->assertSame(['FREE', 0, 0], [$free->key, $free->monthly_price_minor, $free->annual_price_minor]);
        $this->assertFalse($free->includes(Feature::AiAssessment));
        $this->assertTrue($free->includes(Feature::Projects));
        $this->assertSame(0, $free->limitFor(QuotaKey::AiAssessments));
    }

    public function test_only_active_paid_plans_can_be_subscribed_to(): void
    {
        $catalog = app(PlanCatalog::class);

        $this->assertSame('PRO', $catalog->purchasable('PRO', null)?->key);
        $this->assertSame('PRO', $catalog->purchasable('PRO', '1.0.0')?->key);
        $this->assertNull($catalog->purchasable('PRO', '9.9.9'));
        $this->assertNull($catalog->purchasable('FREE', null), 'FREE is the fallback, never a subscription');
        $this->assertNull($catalog->purchasable('TEAM_READY', null), 'TEAM_READY is reserved');
        $this->assertNull($catalog->purchasable('ENTERPRISE', null));
    }

    public function test_the_listed_catalog_shows_free_then_pro_then_the_reserved_plan(): void
    {
        $listed = app(PlanCatalog::class)->listed();

        $this->assertSame(['FREE', 'PRO', 'TEAM_READY'], array_map(fn (BillingPlan $p): string => $p->key, $listed));
        $this->assertSame(PlanStatus::Reserved, $listed[2]->status);
        $this->assertNull($listed[2]->monthly_price_minor);
    }

    public function test_plans_cannot_be_changed_or_deleted(): void
    {
        $plan = BillingPlan::query()->where('key', 'PRO')->sole();

        $this->assertThrows(fn () => $plan->forceFill(['monthly_price_minor' => 0])->save(), DomainRuleViolation::class);
        $this->assertThrows(fn () => $plan->delete(), DomainRuleViolation::class);
        foreach ([
            fn () => DB::table('billing_plans')->where('id', $plan->id)->update(['monthly_price_minor' => 0]),
            fn () => DB::table('billing_plan_quotas')->where('billing_plan_id', $plan->id)->update(['limit' => null]),
            fn () => DB::table('billing_plan_features')->where('billing_plan_id', $plan->id)->delete(),
            fn () => DB::table('billing_plans')->where('id', $plan->id)->delete(),
        ] as $tamper) {
            $this->assertThrows(fn () => DB::transaction($tamper), QueryException::class);
        }
    }

    public function test_the_database_refuses_negative_prices_or_limits_and_unknown_keys(): void
    {
        $plan = BillingPlan::query()->where('key', 'PRO')->sole();
        foreach ([
            fn () => DB::table('billing_plans')->insert(['id' => strtolower((string) Str::ulid()), 'key' => 'CHEAP', 'version' => '1.0.0', 'catalog_version' => '1.0.0', 'name' => 'x', 'description' => 'x', 'status' => 'ACTIVE', 'currency' => 'USD', 'monthly_price_minor' => -1, 'annual_price_minor' => 0, 'fingerprint' => str_repeat('0', 64)]),
            fn () => DB::table('billing_plan_quotas')->insert(['billing_plan_id' => $plan->id, 'quota_key' => 'FREE_MONEY', 'limit' => 1]),
            fn () => DB::table('billing_plan_features')->insert(['billing_plan_id' => $plan->id, 'feature' => 'ADMIN']),
        ] as $insert) {
            $this->assertThrows(fn () => DB::transaction($insert), QueryException::class);
        }
    }
}
