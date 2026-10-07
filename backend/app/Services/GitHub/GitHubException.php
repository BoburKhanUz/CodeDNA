<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use RuntimeException;

/**
 * A failed GitHub call. The message is a fixed description: never a response
 * body, URL, header or credential, so it is safe to log.
 */
final class GitHubException extends RuntimeException
{
    public function __construct(
        public readonly GitHubError $error,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('GitHub request failed: '.$error->value.($status === null ? '' : " (HTTP {$status})"));
    }
}
