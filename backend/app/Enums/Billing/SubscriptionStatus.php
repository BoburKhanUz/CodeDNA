<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Lifecycle of a subscription (docs/billing/subscription-state-machine.md):
 *
 *     (new) ──► TRIALING ──► ACTIVE ◄──► PAST_DUE
 *                  │           ▲  │          │
 *                  │           │  ▼          │
 *                  │         PAUSED          │
 *                  └──────┬────┴─────────────┘
 *                         ▼
 *                 CANCELED | EXPIRED   (terminal)
 *
 * Terminal states never change again. A subscription grants its plan only
 * while TRIALING, ACTIVE or PAST_DUE, and only until its current period ends.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'TRIALING';
    case Active = 'ACTIVE';
    case PastDue = 'PAST_DUE';
    case Paused = 'PAUSED';
    case Canceled = 'CANCELED';
    case Expired = 'EXPIRED';

    public function isTerminal(): bool
    {
        return $this === self::Canceled || $this === self::Expired;
    }

    /** Whether this status can grant the plan at all (time is checked separately). */
    public function canGrant(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Paused, self::Canceled, self::Expired => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Trialing => in_array($next, [self::Active, self::PastDue, self::Paused, self::Canceled, self::Expired], true),
            self::Active => in_array($next, [self::Active, self::PastDue, self::Paused, self::Canceled, self::Expired], true),
            self::PastDue => in_array($next, [self::Active, self::Paused, self::Canceled, self::Expired], true),
            self::Paused => in_array($next, [self::Active, self::Canceled, self::Expired], true),
            self::Canceled, self::Expired => false,
        };
    }

    /** @return list<string> */
    public static function nonTerminalValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => ! $s->isTerminal())));
    }
}
