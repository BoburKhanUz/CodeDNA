<?php

declare(strict_types=1);

namespace App\Http\Errors;

/**
 * The public API's error vocabulary (docs/api/README.md#error-codes).
 *
 * Kept deliberately small: a code identifies what a client can act on, not
 * every internal failure. Add a case only when clients need to distinguish it.
 */
enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case ValidationFailed = 'VALIDATION_FAILED';
    case RateLimited = 'RATE_LIMITED';
    case InternalError = 'INTERNAL_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::AuthenticationRequired => 401,
            self::Forbidden => 403,
            self::ResourceNotFound => 404,
            self::MethodNotAllowed => 405,
            self::PayloadTooLarge => 413,
            self::CsrfTokenMismatch => 419,
            self::ValidationFailed, self::InvalidCredentials => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::ServiceUnavailable => 503,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be processed.',
            self::AuthenticationRequired => 'Authentication is required.',
            self::InvalidCredentials => 'These credentials do not match our records.',
            self::Forbidden => 'This action is not allowed.',
            self::ResourceNotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'This HTTP method is not supported for this resource.',
            self::PayloadTooLarge => 'The request body is too large.',
            self::CsrfTokenMismatch => 'CSRF token mismatch. Request a new token and retry.',
            self::ValidationFailed => 'The given data was invalid.',
            self::RateLimited => 'Too many requests. Retry later.',
            self::InternalError => 'An unexpected error occurred.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
        };
    }
}
