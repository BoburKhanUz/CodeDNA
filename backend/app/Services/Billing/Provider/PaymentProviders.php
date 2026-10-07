<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

/**
 * The configured payment provider (codedna.billing.provider). "none" (the
 * default) means no provider: no webhook is accepted and nobody can buy a
 * plan, while every user keeps the FREE plan.
 */
final class PaymentProviders
{
    private ?PaymentProvider $configured = null;

    /**
     * @param  array<string, mixed>  $config  config('codedna.billing')
     */
    public function __construct(private readonly array $config) {}

    public function configured(): ?PaymentProvider
    {
        $name = $this->config['provider'] ?? 'none';
        if ($name === FakePaymentProvider::NAME) {
            return $this->configured ??= new FakePaymentProvider((string) ($this->config['webhook_secret'] ?? ''), (int) ($this->config['webhook_tolerance_seconds'] ?? 300));
        }

        return null;
    }

    /** The provider with this name, only if it is the configured one. */
    public function named(string $name): ?PaymentProvider
    {
        $provider = $this->configured();

        return $provider !== null && $provider->name() === $name ? $provider : null;
    }
}
