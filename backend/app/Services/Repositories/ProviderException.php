<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use RuntimeException;

/**
 * A failed provider request (Phase 28). The message is the error kind only:
 * provider bodies, URLs, headers and tokens never enter an exception.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly ProviderError $error,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('Repository provider request failed: '.$error->value);
    }
}
