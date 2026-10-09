<?php

declare(strict_types=1);

namespace App\Enums\Repositories;

/**
 * Why a GitLab or Bitbucket import failed (Phase 28), stored on the import
 * and shown to the project's members. Never a provider message, URL, header
 * or credential.
 */
enum ProviderImportFailure: string
{
    case AuthRequired = 'PROVIDER_AUTH_REQUIRED';
    case RepositoryNotFound = 'PROVIDER_REPOSITORY_NOT_FOUND';
    case BranchNotFound = 'PROVIDER_BRANCH_NOT_FOUND';
    case RateLimited = 'PROVIDER_RATE_LIMITED';
    case Unavailable = 'PROVIDER_UNAVAILABLE';
    case ImportFailed = 'PROVIDER_IMPORT_FAILED';
    // The downloaded archive failed the source snapshot checks (Phase 07 limits).
    case ArchiveInvalid = 'SOURCE_ARCHIVE_INVALID';
    case ArchiveUnsafe = 'SOURCE_ARCHIVE_UNSAFE';
    case ArchiveTooLarge = 'SOURCE_ARCHIVE_TOO_LARGE';
    case UncompressedSizeExceeded = 'SOURCE_UNCOMPRESSED_SIZE_EXCEEDED';
    case FileCountExceeded = 'SOURCE_FILE_COUNT_EXCEEDED';
    case FileTooLarge = 'SOURCE_FILE_TOO_LARGE';
    case ProjectArchived = 'PROJECT_ARCHIVED';
}
