<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Enums\GitHub\GitHubFailure;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use Illuminate\Support\Facades\Log;

/**
 * Maps GitHub failures to safe application codes. A missing or forbidden
 * resource means different things depending on what was asked for, so the
 * caller names that code. GitHub's own messages are never passed on.
 */
final class GitHubErrors
{
    public static function api(GitHubException $e, ErrorCode $missing, string $operation): ApiException
    {
        self::log($e, $operation);

        return match ($e->error) {
            GitHubError::RateLimited => new ApiException(ErrorCode::GitHubRateLimited, headers: ['Retry-After' => (string) ($e->retryAfterSeconds ?? 60)]),
            GitHubError::NotConfigured => new ApiException(ErrorCode::GitHubNotConfigured),
            GitHubError::Unauthorized => new ApiException(ErrorCode::GitHubAuthRequired),
            GitHubError::NotFound, GitHubError::Forbidden => new ApiException($missing),
            default => new ApiException(ErrorCode::GitHubUnavailable),
        };
    }

    public static function failure(GitHubException $e, GitHubFailure $missing, string $operation): GitHubFailure
    {
        self::log($e, $operation);

        return match ($e->error) {
            GitHubError::RateLimited => GitHubFailure::RateLimited,
            GitHubError::NotConfigured => GitHubFailure::NotConfigured,
            GitHubError::Unauthorized => GitHubFailure::AuthRequired,
            GitHubError::NotFound, GitHubError::Forbidden => $missing,
            GitHubError::TooLarge => GitHubFailure::ArchiveTooLarge,
            GitHubError::Timeout, GitHubError::Unavailable => GitHubFailure::Unavailable,
            default => GitHubFailure::ImportFailed,
        };
    }

    /** Only the failure kind and HTTP status: never a body, URL, header or token. */
    private static function log(GitHubException $e, string $operation): void
    {
        Log::warning('github.request_failed', [
            'operation' => $operation,
            'error' => $e->error->value,
            'status' => $e->status,
            'retry_after' => $e->retryAfterSeconds,
        ]);
    }
}
