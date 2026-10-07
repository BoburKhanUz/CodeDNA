<?php

declare(strict_types=1);

namespace App\Services\Billing\Provider;

use RuntimeException;

/**
 * A webhook that is not processed: a bad signature, a stale timestamp or a
 * malformed body. The reason is a fixed code, never payload text.
 */
final class WebhookRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
