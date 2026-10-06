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
    // Projects and source snapshots (Phase 07).
    case ProjectArchived = 'PROJECT_ARCHIVED';
    case InvalidSourceType = 'INVALID_SOURCE_TYPE';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case SourceArchiveInvalid = 'SOURCE_ARCHIVE_INVALID';
    case SourceArchiveUnsafe = 'SOURCE_ARCHIVE_UNSAFE';
    case SourceArchiveTooLarge = 'SOURCE_ARCHIVE_TOO_LARGE';
    case SourceUncompressedSizeExceeded = 'SOURCE_UNCOMPRESSED_SIZE_EXCEEDED';
    case SourceFileCountExceeded = 'SOURCE_FILE_COUNT_EXCEEDED';
    case SourceFileTooLarge = 'SOURCE_FILE_TOO_LARGE';
    // Analysis runs (Phase 10).
    case AnalysisNotCompleted = 'ANALYSIS_NOT_COMPLETED';
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
            self::PayloadTooLarge, self::SourceArchiveTooLarge => 413,
            self::CsrfTokenMismatch => 419,
            self::ProjectArchived, self::InvalidSourceType, self::AnalysisNotCompleted => 409,
            self::ValidationFailed, self::InvalidCredentials, self::IdempotencyKeyReused,
            self::SourceArchiveInvalid, self::SourceArchiveUnsafe, self::SourceUncompressedSizeExceeded,
            self::SourceFileCountExceeded, self::SourceFileTooLarge => 422,
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
            self::ProjectArchived => 'This project is archived and cannot be changed.',
            self::InvalidSourceType => 'This project does not accept uploaded source.',
            self::IdempotencyKeyReused => 'This Idempotency-Key was already used for a different upload.',
            self::SourceArchiveInvalid => 'The file is not a valid ZIP archive.',
            self::SourceArchiveUnsafe => 'The archive contains an unsafe entry.',
            self::SourceArchiveTooLarge => 'The archive is larger than the upload limit.',
            self::SourceUncompressedSizeExceeded => 'The archive expands beyond the allowed total size.',
            self::SourceFileCountExceeded => 'The archive contains more files than allowed.',
            self::SourceFileTooLarge => 'The archive contains a file larger than allowed.',
            self::AnalysisNotCompleted => 'This analysis has no result: it has not succeeded.',
            self::InternalError => 'An unexpected error occurred.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
        };
    }
}
