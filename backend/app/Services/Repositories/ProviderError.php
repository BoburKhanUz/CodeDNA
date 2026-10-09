<?php

declare(strict_types=1);

namespace App\Services\Repositories;

/** Why a provider request failed (Phase 28). Never carries a body, URL, header or token. */
enum ProviderError: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case Timeout = 'timeout';
    case InvalidResponse = 'invalid_response';
    case TooLarge = 'too_large';
    case RedirectRejected = 'redirect_rejected';
    case Unsupported = 'unsupported';
}
