<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;
use RuntimeException;

/**
 * An expected, client-facing failure with a stable error code. Its message is
 * shown to API clients, so it must never contain internal details.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers  response headers, e.g. Retry-After
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message ?? $errorCode->defaultMessage());
    }
}
