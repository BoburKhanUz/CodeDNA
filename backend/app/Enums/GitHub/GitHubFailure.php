<?php

declare(strict_types=1);

namespace App\Enums\GitHub;

/**
 * Why a GitHub operation failed, as stored on an import and shown to its
 * owner. Never a raw GitHub message, URL, header or credential.
 */
enum GitHubFailure: string
{
    case NotConfigured = 'GITHUB_NOT_CONFIGURED';
    case AuthRequired = 'GITHUB_AUTH_REQUIRED';
    case InstallationRequired = 'GITHUB_INSTALLATION_REQUIRED';
    case RepositoryNotFound = 'GITHUB_REPOSITORY_NOT_FOUND';
    case BranchNotFound = 'GITHUB_BRANCH_NOT_FOUND';
    case RateLimited = 'GITHUB_RATE_LIMITED';
    case Unavailable = 'GITHUB_UNAVAILABLE';
    case ImportFailed = 'GITHUB_IMPORT_FAILED';
    // The archive GitHub returned failed the source snapshot checks (Phase 07 limits).
    case ArchiveInvalid = 'SOURCE_ARCHIVE_INVALID';
    case ArchiveUnsafe = 'SOURCE_ARCHIVE_UNSAFE';
    case ArchiveTooLarge = 'SOURCE_ARCHIVE_TOO_LARGE';
    case UncompressedSizeExceeded = 'SOURCE_UNCOMPRESSED_SIZE_EXCEEDED';
    case FileCountExceeded = 'SOURCE_FILE_COUNT_EXCEEDED';
    case FileTooLarge = 'SOURCE_FILE_TOO_LARGE';
    case ProjectArchived = 'PROJECT_ARCHIVED';
}
