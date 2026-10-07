<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Exceptions\DomainRuleViolation;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\BillingWebhookEvent;
use App\Models\User;
use App\Services\Billing\Provider\FakePaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingFixtures;
use Tests\Support\SendsBillingWebhooks;
use Tests\TestCase;

/**
 * Phase 23: the webhook boundary. Nothing is parsed or stored before the
 * signature checks out; each provider event is processed exactly once.
 */
final class BillingWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SendsBillingWebhooks;

    private User $user;

    private string $customer;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeBillingProvider();
        $this->user = User::factory()->create();
        $this->customer = $this->customerRef($this->user);
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
    }

    private function activation(array $overrides = []): array
    {
        $at = Carbon::now()->subMinute();

        return $this->billingEvent('SUBSCRIPTION_ACTIVATED', $this->customer, 'sub_1', $at, ['plan' => ['key' => 'PRO'], 'period' => $this->period($at), ...$overrides]);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame([0, 0], [BillingWebhookEvent::query()->count(), BillingSubscription::query()->count()]);
    }

    public function test_a_valid_event_is_applied_once_and_audited_without_its_payload(): void
    {
        $body = $this->activation();

        $this->sendWebhook($body)->assertOk()->assertExactJson(['data' => ['type' => 'billing_webhook_receipt', 'status' => 'processed', 'outcome' => 'APPLIED']]);

        $row = BillingWebhookEvent::query()->sole();
        $this->assertSame([$body['id'], 'SUBSCRIPTION_ACTIVATED', hash('sha256', (string) json_encode($body)), 'APPLIED'],
            [$row->provider_event_id, $row->event_type->value, $row->payload_sha256, $row->outcome->value]);
        $this->assertNotNull($row->processed_at);
        $this->assertSame($row->id, BillingSubscriptionEvent::query()->sole()->billing_webhook_event_id);
    }

    public function test_a_duplicate_delivery_changes_nothing(): void
    {
        $body = $this->activation();
        $this->sendWebhook($body)->assertJsonPath('data.status', 'processed');

        for ($i = 0; $i < 3; $i++) {
            $this->sendWebhook($body)->assertOk()->assertJsonPath('data.status', 'duplicate')->assertJsonPath('data.outcome', 'APPLIED');
        }

        $this->assertSame([1, 1, 1], [BillingWebhookEvent::query()->count(), BillingSubscription::query()->count(), BillingSubscriptionEvent::query()->count()]);
        $this->assertNotEmpty(array_filter($this->logs, fn (MessageLogged $l): bool => $l->message === 'billing.webhook.duplicate'));
    }

    public function test_a_duplicate_cancellation_does_not_cancel_a_later_subscription(): void
    {
        $at = Carbon::now()->subDays(40);
        $this->sendWebhook($this->billingEvent('SUBSCRIPTION_ACTIVATED', $this->customer, 'sub_old', $at, ['plan' => ['key' => 'PRO'], 'period' => $this->period($at)]));
        $cancel = $this->billingEvent('SUBSCRIPTION_CANCELED', $this->customer, 'sub_old', $at->copy()->addDays(10));
        $this->sendWebhook($cancel)->assertJsonPath('data.outcome', 'APPLIED');
        $this->sendWebhook($this->activation(['subscription' => 'sub_new']))->assertJsonPath('data.outcome', 'APPLIED');

        $this->sendWebhook($cancel)->assertJsonPath('data.status', 'duplicate');

        $this->assertSame('ACTIVE', BillingSubscription::query()->where('provider_subscription_ref', 'sub_new')->sole()->status->value);
    }

    /**
     * @return array<string, array{\Closure(string): array{0: string, 1: int|null, 2: string|null, 3: string|null}}>
     */
    public static function forgeries(): array
    {
        return [
            'wrong secret' => [fn (string $p): array => [$p, null, 'not-the-secret-not-the-secret-not-the-secret', null]],
            'no signature' => [fn (string $p): array => [$p, null, null, '']],
            'malformed header' => [fn (string $p): array => [$p, null, null, 'sha256=abc']],
            'uppercase hex' => [fn (string $p): array => [$p, null, null, strtoupper(FakePaymentProvider::sign($p, BillingFixtures::SECRET, Carbon::now()->getTimestamp()))]],
            'replayed: too old' => [fn (string $p): array => [$p, Carbon::now()->subMinutes(6)->getTimestamp(), null, null]],
            'from the future' => [fn (string $p): array => [$p, Carbon::now()->addMinutes(6)->getTimestamp(), null, null]],
            'body changed after signing' => [fn (string $p): array => [$p.' ', null, null, FakePaymentProvider::sign($p, BillingFixtures::SECRET, Carbon::now()->getTimestamp())]],
        ];
    }

    #[DataProvider('forgeries')]
    public function test_forged_or_replayed_signatures_are_refused_before_anything_is_stored(\Closure $forge): void
    {
        [$payload, $timestamp, $secret, $signature] = $forge((string) json_encode($this->activation()));

        $this->sendWebhook($payload, $timestamp, $secret, $signature)
            ->assertStatus(400)->assertJsonPath('error.code', 'BAD_REQUEST')->assertJsonPath('error.message', 'The webhook signature is invalid.');

        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        return [
            'not json' => ['{not json'],
            'a list' => ['[1, 2]'],
            'unknown type' => ['{"id":"evt_1","type":"GRANT_PRO","occurred_at":"2026-10-01T00:00:00Z","customer":"cus_1","subscription":"sub_1"}'],
            'extra field' => ['{"id":"evt_1","type":"SUBSCRIPTION_RENEWED","occurred_at":"2026-10-01T00:00:00Z","customer":"cus_1","subscription":"sub_1","user_id":"x"}'],
            'html in a reference' => ['{"id":"evt_1","type":"SUBSCRIPTION_RENEWED","occurred_at":"2026-10-01T00:00:00Z","customer":"<script>","subscription":"sub_1"}'],
            'bad time' => ['{"id":"evt_1","type":"SUBSCRIPTION_RENEWED","occurred_at":"yesterday","customer":"cus_1","subscription":"sub_1"}'],
            'no event id' => ['{"type":"SUBSCRIPTION_RENEWED","occurred_at":"2026-10-01T00:00:00Z","customer":"cus_1","subscription":"sub_1"}'],
            'plan injection' => ['{"id":"evt_1","type":"SUBSCRIPTION_ACTIVATED","occurred_at":"2026-10-01T00:00:00Z","customer":"cus_1","subscription":"sub_1","plan":{"key":"PRO; DROP TABLE users"}}'],
            'deeply nested' => ['{"id":"evt_1","type":"SUBSCRIPTION_RENEWED","occurred_at":"2026-10-01T00:00:00Z","customer":"cus_1","subscription":"sub_1","plan":{"key":{"a":{"b":{"c":{"d":{"e":{"f":{"g":1}}}}}}}}}'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_signed_but_malformed_bodies_are_refused(string $payload): void
    {
        $this->sendWebhook($payload)->assertStatus(400)->assertJsonPath('error.message', 'The webhook body is not a supported event.');

        $this->assertNothingStored();
        $this->assertStringNotContainsString('<script>', (string) json_encode(array_map(fn (MessageLogged $l) => [$l->message, $l->context], $this->logs)));
    }

    public function test_oversized_bodies_are_refused_before_verification(): void
    {
        $this->sendWebhook(str_repeat('x', 65537))->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        $this->assertNothingStored();
    }

    public function test_only_the_configured_provider_has_a_webhook(): void
    {
        $this->sendWebhook($this->activation(), provider: 'stripe')->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        config(['codedna.billing.provider' => 'none']);
        $this->app->forgetScopedInstances();
        $this->sendWebhook($this->activation())->assertNotFound();

        $this->assertNothingStored();
    }

    public function test_webhooks_need_no_session_and_ignore_cookies(): void
    {
        $this->fromBrowser()->withCredentials();
        $this->sendWebhook($this->activation())->assertOk();
        $this->assertSame('ACTIVE', BillingSubscription::query()->sole()->status->value);
    }

    public function test_logs_never_contain_signatures_secrets_or_payloads(): void
    {
        $body = $this->activation();
        $payload = (string) json_encode($body);
        $signature = FakePaymentProvider::sign($payload, BillingFixtures::SECRET, Carbon::now()->getTimestamp());
        $this->sendWebhook($payload, signature: $signature);
        $this->sendWebhook($payload, signature: $signature);
        $this->sendWebhook($payload, secret: 'not-the-secret-not-the-secret-not-the-secret');

        $logged = (string) json_encode(array_map(fn (MessageLogged $l) => [$l->message, $l->context], $this->logs));
        foreach ([BillingFixtures::SECRET, substr($signature, -64), $this->customer, 'sub_1', $payload] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
        foreach ($this->logs as $log) {
            if (str_starts_with($log->message, 'billing.')) {
                $this->assertSame([], array_diff(array_keys($log->context), [
                    'provider', 'provider_event_id', 'type', 'webhook_event_id', 'reason', 'outcome', 'user_id', 'subscription_id',
                    'from_status', 'to_status', 'feature', 'plan', 'error_code', 'quota', 'limit', 'used', 'resource_type', 'resource_id',
                ]), $log->message);
            }
        }
    }

    public function test_a_processed_event_record_cannot_be_rewritten(): void
    {
        $this->sendWebhook($this->activation());
        $row = BillingWebhookEvent::query()->sole();

        $this->assertThrows(fn () => $row->forceFill(['outcome' => 'STALE'])->save(), DomainRuleViolation::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('billing_webhook_events')->where('id', $row->id)->update(['outcome' => 'STALE'])), QueryException::class);
    }
}
