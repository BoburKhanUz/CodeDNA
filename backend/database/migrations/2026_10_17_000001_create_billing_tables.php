<?php

declare(strict_types=1);

use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Enums\Billing\SubscriptionEventType;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Billing\UsageOutcome;
use App\Enums\Billing\WebhookOutcome;
use App\Services\Billing\Catalog\PlanCatalogV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Billing and SaaS foundation (Phase 23, docs/billing/billing-architecture.md).
 *
 * Additive only: no existing table or row changes. Users without a
 * subscription row are on the FREE plan by deterministic fallback, so
 * existing users need no backfill and keep working.
 *
 * - billing_plans, billing_plan_features, billing_plan_quotas: the
 *   server-owned, versioned catalog (seeded here from PlanCatalogV1).
 *   Immutable: a commercial change is a new plan version.
 * - billing_customers: a user's identity at a payment provider. Immutable.
 * - billing_subscriptions: one row per provider subscription; at most one
 *   current (non-terminal) per user. Terminal rows never change.
 * - billing_subscription_events: append-only history of every applied change.
 * - billing_webhook_events: one row per provider event ID (idempotency and
 *   audit), with the normalized event. No raw payload is stored, only its
 *   SHA-256.
 * - billing_usage_events: append-only usage ledger. billing_usage_counters
 *   is its per-period aggregate, used for atomic quota checks.
 *
 * Triggers refuse updates of immutable rows (plans also refuse deletes).
 * Deletes are refused by the models and restricted by foreign keys, as for
 * the other domain tables; no API deletes billing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 32);
            $table->string('version', 16);
            $table->string('catalog_version', 16);
            $table->string('name', 64);
            $table->string('description', 255);
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->bigInteger('monthly_price_minor')->nullable();
            $table->bigInteger('annual_price_minor')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['key', 'version']);
        });

        Schema::create('billing_plan_features', function (Blueprint $table) {
            $table->foreignUlid('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('feature', 32);
            $table->primary(['billing_plan_id', 'feature']);
        });

        Schema::create('billing_plan_quotas', function (Blueprint $table) {
            $table->foreignUlid('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('quota_key', 32);
            // NULL: unlimited.
            $table->bigInteger('limit')->nullable();
            $table->primary(['billing_plan_id', 'quota_key']);
        });

        Schema::create('billing_customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_customer_ref', 255);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'provider_customer_ref']);
            $table->unique(['user_id', 'provider']);
            $table->unique(['id', 'user_id', 'provider']);
        });

        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('billing_customer_id');
            $table->foreignUlid('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_subscription_ref', 255);
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // The provider time of the last applied event: older events never revert it.
            $table->timestamp('provider_event_at');
            $table->timestamps();
            $table->unique(['provider', 'provider_subscription_ref']);
            $table->unique(['id', 'user_id']);
            $table->foreign(['billing_customer_id', 'user_id', 'provider'], 'billing_subscriptions_customer_foreign')
                ->references(['id', 'user_id', 'provider'])->on('billing_customers')->restrictOnDelete();
        });

        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 32);
            $table->string('provider_event_id', 255);
            $table->string('event_type', 40);
            $table->timestamp('occurred_at');
            // The normalized, provider-neutral event (no raw payload): what an
            // event that arrived before its subscription needs to be replayed.
            $table->string('provider_customer_ref', 255);
            $table->string('provider_subscription_ref', 255);
            $table->string('plan_key', 32)->nullable();
            $table->string('plan_version', 16)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('at_period_end')->default(false);
            $table->char('payload_sha256', 64);
            $table->string('outcome', 24);
            $table->ulid('billing_subscription_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['provider', 'provider_subscription_ref', 'outcome']);
            $table->foreign('billing_subscription_id')->references('id')->on('billing_subscriptions')->restrictOnDelete();
        });

        Schema::create('billing_subscription_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('billing_subscription_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->ulid('from_plan_id')->nullable();
            $table->ulid('to_plan_id');
            $table->ulid('billing_webhook_event_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->foreign(['billing_subscription_id', 'user_id'], 'billing_subscription_events_subscription_foreign')
                ->references(['id', 'user_id'])->on('billing_subscriptions')->restrictOnDelete();
            $table->foreign('from_plan_id')->references('id')->on('billing_plans')->restrictOnDelete();
            $table->foreign('to_plan_id')->references('id')->on('billing_plans')->restrictOnDelete();
            $table->foreign('billing_webhook_event_id')->references('id')->on('billing_webhook_events')->restrictOnDelete();
        });

        Schema::create('billing_usage_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('billing_subscription_id')->nullable();
            $table->foreignUlid('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('quota_key', 32);
            $table->string('outcome', 16);
            $table->bigInteger('amount');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('resource_type', 32)->nullable();
            $table->ulid('resource_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->foreign(['billing_subscription_id', 'user_id'], 'billing_usage_events_subscription_foreign')
                ->references(['id', 'user_id'])->on('billing_subscriptions')->restrictOnDelete();
        });

        Schema::create('billing_usage_counters', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('quota_key', 32);
            $table->timestamp('period_start');
            $table->bigInteger('used')->default(0);
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['user_id', 'quota_key', 'period_start']);
        });

        $in = static fn (array $cases): string => implode(', ', array_map(fn ($c): string => "'".$c->value."'", $cases));
        $features = $in(Feature::cases());
        $quotas = $in(QuotaKey::cases());
        $statuses = $in(SubscriptionStatus::cases());
        $terminal = "'".SubscriptionStatus::Canceled->value."', '".SubscriptionStatus::Expired->value."'";
        $eventTypes = $in(SubscriptionEventType::cases());
        $outcomes = $in(WebhookOutcome::cases());
        $usage = $in(UsageOutcome::cases());

        DB::statement(<<<'SQL'
            ALTER TABLE billing_plans
                ADD CONSTRAINT billing_plans_key_format CHECK (key ~ '^[A-Z][A-Z_]{1,31}$' AND version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
                ADD CONSTRAINT billing_plans_status CHECK (status IN ('ACTIVE', 'RESERVED', 'RETIRED')),
                ADD CONSTRAINT billing_plans_currency CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT billing_plans_prices CHECK (
                    (monthly_price_minor IS NULL OR monthly_price_minor >= 0) AND (annual_price_minor IS NULL OR annual_price_minor >= 0)
                    AND ((status = 'RESERVED') = (monthly_price_minor IS NULL)) AND ((monthly_price_minor IS NULL) = (annual_price_minor IS NULL)))
        SQL);
        DB::statement("ALTER TABLE billing_plan_features ADD CONSTRAINT billing_plan_features_feature CHECK (feature IN ({$features}))");
        DB::statement("ALTER TABLE billing_plan_quotas ADD CONSTRAINT billing_plan_quotas_key CHECK (quota_key IN ({$quotas})),
            ADD CONSTRAINT billing_plan_quotas_limit CHECK (\"limit\" IS NULL OR \"limit\" >= 0)");
        DB::statement(<<<SQL
            ALTER TABLE billing_subscriptions
                ADD CONSTRAINT billing_subscriptions_status CHECK (status IN ({$statuses})),
                ADD CONSTRAINT billing_subscriptions_period CHECK (current_period_end > current_period_start AND current_period_start >= started_at),
                ADD CONSTRAINT billing_subscriptions_terminal_ended CHECK ((status IN ({$terminal})) = (ended_at IS NOT NULL)),
                ADD CONSTRAINT billing_subscriptions_canceled_at CHECK (status <> 'CANCELED' OR canceled_at IS NOT NULL)
        SQL);
        // At most one current subscription per user.
        DB::statement("CREATE UNIQUE INDEX billing_subscriptions_one_current ON billing_subscriptions (user_id) WHERE status NOT IN ({$terminal})");
        DB::statement("ALTER TABLE billing_webhook_events ADD CONSTRAINT billing_webhook_events_outcome CHECK (outcome IN ({$outcomes})),
            ADD CONSTRAINT billing_webhook_events_type CHECK (event_type IN ({$eventTypes})),
            ADD CONSTRAINT billing_webhook_events_processed CHECK ((outcome IN ('RECEIVED', 'DEFERRED')) = (processed_at IS NULL))");
        DB::statement("ALTER TABLE billing_subscription_events ADD CONSTRAINT billing_subscription_events_type CHECK (type IN ({$eventTypes})),
            ADD CONSTRAINT billing_subscription_events_status CHECK (to_status IN ({$statuses}) AND (from_status IS NULL OR from_status IN ({$statuses})))");
        DB::statement(<<<SQL
            ALTER TABLE billing_usage_events
                ADD CONSTRAINT billing_usage_events_key CHECK (quota_key IN ({$quotas})),
                ADD CONSTRAINT billing_usage_events_outcome CHECK (outcome IN ({$usage})),
                ADD CONSTRAINT billing_usage_events_amount CHECK (
                    (outcome = 'ACCEPTED' AND amount > 0 AND resource_id IS NOT NULL)
                    OR (outcome = 'REFUNDED' AND amount < 0 AND resource_id IS NOT NULL)
                    OR (outcome = 'REJECTED' AND amount > 0)),
                ADD CONSTRAINT billing_usage_events_resource CHECK ((resource_type IS NULL) = (resource_id IS NULL)),
                ADD CONSTRAINT billing_usage_events_period CHECK (period_end > period_start)
        SQL);
        // A resource is charged at most once and refunded at most once (retries never double-charge).
        DB::statement("CREATE UNIQUE INDEX billing_usage_events_once ON billing_usage_events (quota_key, resource_type, resource_id, outcome) WHERE outcome IN ('ACCEPTED', 'REFUNDED')");
        DB::statement("ALTER TABLE billing_usage_counters ADD CONSTRAINT billing_usage_counters_key CHECK (quota_key IN ({$quotas})),
            ADD CONSTRAINT billing_usage_counters_used CHECK (used >= 0)");

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION billing_refuse_change() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION '%: billing records are immutable', TG_TABLE_NAME USING ERRCODE = 'check_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER billing_plans_immutable BEFORE UPDATE OR DELETE ON billing_plans FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
            CREATE TRIGGER billing_plan_features_immutable BEFORE UPDATE OR DELETE ON billing_plan_features FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
            CREATE TRIGGER billing_plan_quotas_immutable BEFORE UPDATE OR DELETE ON billing_plan_quotas FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
            CREATE TRIGGER billing_customers_immutable BEFORE UPDATE ON billing_customers FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
            CREATE TRIGGER billing_subscription_events_immutable BEFORE UPDATE ON billing_subscription_events FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();
            CREATE TRIGGER billing_usage_events_immutable BEFORE UPDATE ON billing_usage_events FOR EACH ROW EXECUTE FUNCTION billing_refuse_change();

            -- A subscription keeps its identity, and a terminal one never changes.
            CREATE OR REPLACE FUNCTION billing_subscriptions_guard() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.status IN ({$terminal}) THEN
                    RAISE EXCEPTION 'billing_subscriptions: a terminal subscription never changes' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.billing_customer_id IS DISTINCT FROM OLD.billing_customer_id
                    OR NEW.provider IS DISTINCT FROM OLD.provider OR NEW.provider_subscription_ref IS DISTINCT FROM OLD.provider_subscription_ref
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at OR NEW.provider_event_at < OLD.provider_event_at THEN
                    RAISE EXCEPTION 'billing_subscriptions: identity and event order are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER billing_subscriptions_guarded BEFORE UPDATE ON billing_subscriptions FOR EACH ROW EXECUTE FUNCTION billing_subscriptions_guard();

            -- A webhook event is decided once: RECEIVED (or DEFERRED until its
            -- subscription is activated) -> a final outcome. Its content never changes.
            CREATE OR REPLACE FUNCTION billing_webhook_events_guard() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.outcome NOT IN ('RECEIVED', 'DEFERRED') OR NEW.provider IS DISTINCT FROM OLD.provider OR NEW.provider_event_id IS DISTINCT FROM OLD.provider_event_id
                    OR NEW.event_type IS DISTINCT FROM OLD.event_type OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
                    OR NEW.payload_sha256 IS DISTINCT FROM OLD.payload_sha256 OR NEW.received_at IS DISTINCT FROM OLD.received_at
                    OR NEW.provider_subscription_ref IS DISTINCT FROM OLD.provider_subscription_ref
                    OR NEW.provider_customer_ref IS DISTINCT FROM OLD.provider_customer_ref
                    OR NEW.plan_key IS DISTINCT FROM OLD.plan_key OR NEW.period_end IS DISTINCT FROM OLD.period_end THEN
                    RAISE EXCEPTION 'billing_webhook_events: a processed event never changes' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER billing_webhook_events_guarded BEFORE UPDATE ON billing_webhook_events FOR EACH ROW EXECUTE FUNCTION billing_webhook_events_guard();
        SQL);

        $now = Carbon::now();
        foreach (PlanCatalogV1::definitions() as $plan) {
            $id = strtolower((string) Str::ulid());
            DB::table('billing_plans')->insert([
                'id' => $id, 'key' => $plan->key, 'version' => $plan->version, 'catalog_version' => PlanCatalogV1::VERSION,
                'name' => $plan->name, 'description' => $plan->description, 'status' => $plan->status->value,
                'currency' => $plan->currency, 'monthly_price_minor' => $plan->monthlyPriceMinor, 'annual_price_minor' => $plan->annualPriceMinor,
                'fingerprint' => $plan->fingerprint(), 'created_at' => $now,
            ]);
            foreach ($plan->features as $feature) {
                DB::table('billing_plan_features')->insert(['billing_plan_id' => $id, 'feature' => $feature->value]);
            }
            foreach ($plan->quotas as $key => $limit) {
                DB::table('billing_plan_quotas')->insert(['billing_plan_id' => $id, 'quota_key' => $key, 'limit' => $limit]);
            }
        }
    }

    public function down(): void
    {
        foreach (['billing_usage_counters', 'billing_usage_events', 'billing_subscription_events', 'billing_webhook_events',
            'billing_subscriptions', 'billing_customers', 'billing_plan_quotas', 'billing_plan_features', 'billing_plans'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS billing_refuse_change(); DROP FUNCTION IF EXISTS billing_subscriptions_guard(); DROP FUNCTION IF EXISTS billing_webhook_events_guard();');
    }
};
