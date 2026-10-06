<?php

declare(strict_types=1);

namespace App\Support\Sources;

/**
 * What inspection learned about an archive. Paths are kept in memory only
 * (for the language heuristic); they are never stored or logged.
 */
final readonly class ArchiveSummary
{
    /**
     * @param  list<string>  $filePaths  regular files counted in $fileCount
     */
    public function __construct(
        public int $entryCount,
        public int $fileCount,
        public int $directoryCount,
        public int $uncompressedBytes,
        public array $filePaths,
    ) {}
}
