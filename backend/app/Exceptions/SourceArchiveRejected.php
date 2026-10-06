<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;
use RuntimeException;

/**
 * An uploaded source archive failed inspection.
 *
 * `reason` is a short, fixed machine-readable identifier for logs (e.g.
 * "path_traversal"). Neither it nor the message ever contains entry names,
 * file contents or other archive data.
 */
final class SourceArchiveRejected extends RuntimeException
{
    private function __construct(public readonly ErrorCode $errorCode, public readonly string $reason)
    {
        parent::__construct($errorCode->defaultMessage());
    }

    public static function invalid(string $reason): self
    {
        return new self(ErrorCode::SourceArchiveInvalid, $reason);
    }

    public static function unsafe(string $reason): self
    {
        return new self(ErrorCode::SourceArchiveUnsafe, $reason);
    }

    public static function tooLarge(): self
    {
        return new self(ErrorCode::SourceArchiveTooLarge, 'archive_too_large');
    }

    public static function uncompressedSizeExceeded(): self
    {
        return new self(ErrorCode::SourceUncompressedSizeExceeded, 'uncompressed_size_exceeded');
    }

    public static function fileCountExceeded(): self
    {
        return new self(ErrorCode::SourceFileCountExceeded, 'file_count_exceeded');
    }

    public static function fileTooLarge(): self
    {
        return new self(ErrorCode::SourceFileTooLarge, 'file_too_large');
    }
}
