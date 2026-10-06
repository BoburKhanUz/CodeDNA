<?php

declare(strict_types=1);

namespace App\Support\Sources;

/**
 * Limits applied to uploaded source archives (config('codedna.sources.limits'),
 * docs/api/README.md#upload-limits).
 */
final readonly class SourceArchiveLimits
{
    public function __construct(
        public int $archiveBytes,
        public int $uncompressedBytes,
        public int $files,
        public int $singleFileBytes,
        public int $pathLength,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            archiveBytes: (int) $config['archive_bytes'],
            uncompressedBytes: (int) $config['uncompressed_bytes'],
            files: (int) $config['files'],
            singleFileBytes: (int) $config['single_file_bytes'],
            pathLength: (int) $config['path_length'],
        );
    }

    /**
     * Entries of any kind (files, directories, archive metadata). Directories
     * are not files, but they must be bounded too.
     */
    public function entries(): int
    {
        return $this->files * 2;
    }
}
