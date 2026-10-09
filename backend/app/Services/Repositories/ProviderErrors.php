<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\ProviderImportFailure;
use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use Illuminate\Support\Facades\Log;

/**
 * Maps provider failures to API errors and import failure codes (Phase 28).
 * A NotFound or Forbidden answer means "not available to this user" and
 * becomes the code the caller names. Provider messages are never passed on;
 * the log records the operation, the failure kind and the HTTP status only.
 */
final class ProviderErrors
{
    public static function api(ProviderException $e, RepositoryProviderKey $provider, ErrorCode $missing, string $operation): ApiException
    {
        self::log($e, $provider, $operation);
        $details = ['provider' => $provider->value];

        return match ($e->error) {
            ProviderError::RateLimited => new ApiException(ErrorCode::ProviderRateLimited, null, $details, ['Retry-After' => (string) ($e->retryAfterSeconds ?? 60)]),
            ProviderError::Unauthorized => new ApiException(ErrorCode::ProviderAuthRequired, null, $details),
            ProviderError::NotFound, ProviderError::Forbidden => new ApiException($missing, null, $details),
            default => new ApiException(ErrorCode::ProviderUnavailable, null, $details),
        };
    }

    public static function failure(ProviderException $e, RepositoryProviderKey $provider, ProviderImportFailure $missing, string $operation): ProviderImportFailure
    {
        self::log($e, $provider, $operation);

        return match ($e->error) {
            ProviderError::RateLimited => ProviderImportFailure::RateLimited,
            ProviderError::Unauthorized => ProviderImportFailure::AuthRequired,
            ProviderError::NotFound, ProviderError::Forbidden => $missing,
            ProviderError::TooLarge => ProviderImportFailure::ArchiveTooLarge,
            ProviderError::Timeout, ProviderError::Unavailable => ProviderImportFailure::Unavailable,
            default => ProviderImportFailure::ImportFailed,
        };
    }

    private static function log(ProviderException $e, RepositoryProviderKey $provider, string $operation): void
    {
        Log::warning('repository_provider.request_failed', [
            'provider' => $provider->value,
            'operation' => $operation,
            'error' => $e->error->value,
            'status' => $e->status,
            'retry_after' => $e->retryAfterSeconds,
        ]);
    }
}
