<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use App\Enums\Billing\SubscriptionEventType;
use App\Models\BillingPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;
use SensitiveParameter;
use Throwable;

/**
 * A deterministic stand-in for a real payment provider (local development
 * and tests only; refused in every deployed environment by
 * ConfigurationValidator). It moves no money and calls nothing.
 *
 * Webhooks are signed like most real providers do it: header
 * "X-Billing-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">",
 * with a timestamp tolerance against replay. Body:
 *
 *     {"id": "evt_...", "type": "SUBSCRIPTION_ACTIVATED", "occurred_at": "2026-10-01T00:00:00Z",
 *      "customer": "cus_...", "subscription": "sub_...", "plan": {"key": "PRO", "version": "1.0.0"} | null,
 *      "period": {"start": "...", "end": "..."} | null, "trial_end": "..." | null, "at_period_end": false}
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const NAME = 'fake';

    public const SIGNATURE_HEADER = 'x-billing-signature';

    private const REF = '/^[A-Za-z0-9_\-]{1,128}$/D';

    /** @var list<array{string, string, bool|string}> calls made, for tests */
    public array $calls = [];

    public function __construct(
        #[SensitiveParameter] private readonly string $webhookSecret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(ProviderCapability $capability): bool
    {
        return $capability !== ProviderCapability::FetchSubscription;
    }

    public function createCustomer(User $user): string
    {
        return 'cus_'.strtolower((string) Str::ulid());
    }

    public function createCheckoutSession(string $customerRef, BillingPlan $plan, BillingInterval $interval): CheckoutSession
    {
        $id = 'cs_'.strtolower((string) Str::ulid());
        $this->calls[] = ['checkout', $customerRef, $plan->key.'@'.$plan->version.'/'.$interval->value];

        return new CheckoutSession($id, 'https://billing.invalid/checkout/'.$id, Carbon::now()->addHour());
    }

    public function cancelSubscription(string $subscriptionRef, bool $atPeriodEnd): void
    {
        $this->calls[] = ['cancel', $subscriptionRef, $atPeriodEnd];
    }

    public function changeSubscription(string $subscriptionRef, BillingPlan $plan): void
    {
        $this->calls[] = ['change', $subscriptionRef, $plan->key.'@'.$plan->version];
    }

    public function getSubscription(string $subscriptionRef): ProviderSubscription
    {
        throw new PaymentProviderException('The fake provider keeps no subscription state.');
    }

    /**
     * The signature header for a body, as the fake provider would send it (tests and local tools).
     */
    public static function sign(string $payload, #[SensitiveParameter] string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public function verifyWebhook(string $payload, array $headers): bool
    {
        $header = $headers[self::SIGNATURE_HEADER] ?? '';
        if (preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/D', $header, $m) !== 1) {
            return false;
        }
        $timestamp = (int) $m[1];
        if (abs(Carbon::now()->getTimestamp() - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret), $m[2]);
    }

    public function parseWebhook(string $payload): ProviderEvent
    {
        try {
            $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookRejected('malformed_json');
        }
        if (! is_array($data)) {
            throw new WebhookRejected('malformed_body');
        }
        $allowed = ['id', 'type', 'occurred_at', 'customer', 'subscription', 'plan', 'period', 'trial_end', 'at_period_end'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new WebhookRejected('unexpected_field');
        }
        $type = SubscriptionEventType::tryFrom(self::string($data, 'type', '/^[A-Z_]{1,40}$/D'))
            ?? throw new WebhookRejected('unsupported_type');
        $plan = $data['plan'] ?? null;
        $period = $data['period'] ?? null;
        if (($plan !== null && ! is_array($plan)) || ($period !== null && ! is_array($period))
            || (isset($data['at_period_end']) && ! is_bool($data['at_period_end']))) {
            throw new WebhookRejected('malformed_body');
        }

        return new ProviderEvent(
            provider: self::NAME,
            eventId: self::string($data, 'id', '/^evt_[A-Za-z0-9_\-]{1,124}$/D'),
            type: $type,
            occurredAt: self::time($data['occurred_at'] ?? null) ?? throw new WebhookRejected('malformed_time'),
            customerRef: self::string($data, 'customer', self::REF),
            subscriptionRef: self::string($data, 'subscription', self::REF),
            planKey: $plan === null ? null : self::string($plan, 'key', '/^[A-Z][A-Z_]{1,31}$/D'),
            planVersion: $plan === null || ! isset($plan['version']) ? null : self::string($plan, 'version', '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/D'),
            periodStart: $period === null ? null : (self::time($period['start'] ?? null) ?? throw new WebhookRejected('malformed_time')),
            periodEnd: $period === null ? null : (self::time($period['end'] ?? null) ?? throw new WebhookRejected('malformed_time')),
            trialEndsAt: isset($data['trial_end']) ? (self::time($data['trial_end']) ?? throw new WebhookRejected('malformed_time')) : null,
            atPeriodEnd: (bool) ($data['at_period_end'] ?? false),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key, string $pattern): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new WebhookRejected('malformed_field');
        }

        return $value;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/D', $value) !== 1) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
