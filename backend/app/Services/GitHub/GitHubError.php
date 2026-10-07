<?php

declare(strict_types=1);

namespace App\Services\GitHub;

/**
 * What went wrong talking to GitHub, independent of the operation. Callers
 * map it to an application code for their context (GitHubErrors).
 */
enum GitHubError: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case Unprocessable = 'unprocessable';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case Timeout = 'timeout';
    case InvalidResponse = 'invalid_response';
    case TooLarge = 'too_large';
    case RedirectRejected = 'redirect_rejected';
    case NotConfigured = 'not_configured';
}
