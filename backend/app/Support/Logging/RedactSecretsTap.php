<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;

/**
 * Installs RedactSecrets on a log channel whose driver ignores the
 * "processors" option (single, daily, monthly).
 */
final class RedactSecretsTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactSecrets);
    }
}
