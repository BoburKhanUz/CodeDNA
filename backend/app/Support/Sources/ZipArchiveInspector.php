<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Exceptions\SourceArchiveRejected;

/**
 * Validates an uploaded ZIP archive without extracting it.
 *
 * Nothing from the archive is ever written to disk, executed or interpreted:
 * the inspector reads the ZIP structures (APPNOTE.TXT) and streams each
 * entry's compressed bytes through zlib only to count the real decompressed
 * size and check the CRC-32. This defends against decompression bombs whose
 * headers understate their sizes.
 *
 * Accepted: a single-disk ZIP (optionally ZIP64) whose entries are stored
 * (method 0) or deflated (method 8), unencrypted, with relative, normalized
 * UTF-8 paths, regular files and directories only, within the configured
 * limits. The layout must be unambiguous: no data before the first entry,
 * no overlapping entries, local headers that agree with the central
 * directory, and nothing between the central directory and its end record.
 * Every other reader (including the future analyzer) therefore sees the same
 * entries.
 *
 * Rejection reasons never include entry names or contents.
 */
final readonly class ZipArchiveInspector
{
    private const LOCAL_HEADER_SIGNATURE = 0x04034B50;

    private const CENTRAL_HEADER_SIGNATURE = 0x02014B50;

    private const END_SIGNATURE = "PK\x05\x06";

    private const ZIP64_END_SIGNATURE = 0x06064B50;

    private const ZIP64_LOCATOR_SIGNATURE = 0x07064B50;

    private const END_RECORD_SIZE = 22;

    private const LOCAL_HEADER_SIZE = 30;

    private const CENTRAL_HEADER_SIZE = 46;

    private const ZIP64_LOCATOR_SIZE = 20;

    private const MAX_COMMENT_LENGTH = 0xFFFF;

    /** Small chunks bound the memory one inflate step can produce (deflate expands at most ~1032:1). */
    private const READ_CHUNK_BYTES = 8192;

    private const METHOD_STORED = 0;

    private const METHOD_DEFLATED = 8;

    /** Encrypted (bit 0), strong encryption (bit 6), encrypted central directory (bit 13). */
    private const ENCRYPTION_FLAGS = 0x0001 | 0x0040 | 0x2000;

    private const HOST_UNIX = 3;

    private const HOST_OSX = 19;

    private const UNIX_TYPE_MASK = 0xF000;

    private const UNIX_REGULAR_FILE = 0x8000;

    private const UNIX_DIRECTORY = 0x4000;

    private const UNIX_SYMLINK = 0xA000;

    /** Resource-fork metadata added by macOS's archiver: validated, but not counted as source files. */
    private const MACOS_METADATA_DIRECTORY = '__MACOSX';

    public function __construct(private SourceArchiveLimits $limits) {}

    /**
     * @throws SourceArchiveRejected
     */
    public function inspect(string $path): ArchiveSummary
    {
        $size = @filesize($path);
        if ($size === false) {
            throw SourceArchiveRejected::invalid('unreadable');
        }
        if ($size > $this->limits->archiveBytes) {
            throw SourceArchiveRejected::tooLarge();
        }
        if ($size < self::END_RECORD_SIZE) {
            throw SourceArchiveRejected::invalid('too_small');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw SourceArchiveRejected::invalid('unreadable');
        }

        try {
            // A non-empty ZIP starts with a local file header ("PK\3\4").
            if ($this->read($handle, 0, 4) !== "PK\x03\x04") {
                throw SourceArchiveRejected::invalid('not_a_zip');
            }

            $directory = $this->readEndOfCentralDirectory($handle, $size);
            $entries = $this->readCentralDirectory($handle, $directory);

            return $this->verifyEntries($handle, $entries, $directory['offset']);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Locates the end-of-central-directory record (and the ZIP64 record when
     * present) and returns where the central directory is.
     *
     * @param  resource  $handle
     * @return array{entries: int, offset: int, size: int}
     */
    private function readEndOfCentralDirectory($handle, int $size): array
    {
        $tailLength = min($size, self::END_RECORD_SIZE + self::MAX_COMMENT_LENGTH);
        $tail = $this->read($handle, $size - $tailLength, $tailLength);

        // The record must end exactly at the end of the file (its comment
        // included); search backwards for the last signature that does.
        $position = null;
        $searchEnd = $tailLength - self::END_RECORD_SIZE;
        while ($searchEnd >= 0) {
            $candidate = strrpos(substr($tail, 0, $searchEnd + 4), self::END_SIGNATURE);
            if ($candidate === false) {
                break;
            }
            $commentLength = $this->unpack('v', $tail, $candidate + 20)[1];
            if ($candidate + self::END_RECORD_SIZE + $commentLength === $tailLength) {
                $position = $candidate;
                break;
            }
            $searchEnd = $candidate - 1;
        }
        if ($position === null) {
            throw SourceArchiveRejected::invalid('no_end_record');
        }

        $end = $this->unpack('vdisk/vdirectoryDisk/vdiskEntries/ventries/VdirectorySize/VdirectoryOffset', $tail, $position + 4);
        $endOffset = $size - $tailLength + $position;

        $entries = $end['entries'];
        $directorySize = $end['directorySize'];
        $directoryOffset = $end['directoryOffset'];
        $directoryEnd = $endOffset;

        $locatorOffset = $endOffset - self::ZIP64_LOCATOR_SIZE;
        $isZip64 = $locatorOffset >= 0
            && $this->unpack('V', $this->read($handle, $locatorOffset, 4))[1] === self::ZIP64_LOCATOR_SIGNATURE;

        if ($isZip64) {
            $locator = $this->unpack('Vsignature/Vdisk/PrecordOffset/VdiskCount', $this->read($handle, $locatorOffset, self::ZIP64_LOCATOR_SIZE));
            $recordOffset = $locator['recordOffset'];
            if ($locator['disk'] !== 0 || $locator['diskCount'] !== 1 || $recordOffset < 0 || $recordOffset + 56 > $locatorOffset) {
                throw SourceArchiveRejected::invalid('bad_zip64_locator');
            }
            $record = $this->unpack(
                'Vsignature/PrecordSize/vmadeBy/vneeded/Vdisk/VdirectoryDisk/PdiskEntries/Pentries/PdirectorySize/PdirectoryOffset',
                $this->read($handle, $recordOffset, 56),
            );
            if ($record['signature'] !== self::ZIP64_END_SIGNATURE
                || $recordOffset + 12 + $record['recordSize'] !== $locatorOffset
                || $record['disk'] !== 0 || $record['directoryDisk'] !== 0
                || $record['diskEntries'] !== $record['entries']) {
                throw SourceArchiveRejected::invalid('bad_zip64_end_record');
            }
            $entries = $record['entries'];
            $directorySize = $record['directorySize'];
            $directoryOffset = $record['directoryOffset'];
            $directoryEnd = $recordOffset;
        } elseif ($end['disk'] !== 0 || $end['directoryDisk'] !== 0 || $end['diskEntries'] !== $end['entries']) {
            throw SourceArchiveRejected::invalid('multi_disk');
        }

        if ($entries < 0 || $directorySize < 0 || $directoryOffset < 0) {
            throw SourceArchiveRejected::invalid('bad_end_record');
        }
        if ($entries === 0) {
            throw SourceArchiveRejected::invalid('empty');
        }
        if ($entries > $this->limits->entries()) {
            throw SourceArchiveRejected::fileCountExceeded();
        }
        // The central directory must end exactly where the end records begin,
        // and must be large enough for the declared number of entries.
        if ($directoryOffset + $directorySize !== $directoryEnd
            || $directorySize < $entries * self::CENTRAL_HEADER_SIZE) {
            throw SourceArchiveRejected::invalid('bad_central_directory_bounds');
        }

        return ['entries' => $entries, 'offset' => $directoryOffset, 'size' => $directorySize];
    }

    /**
     * Parses and validates every central directory entry (names, types,
     * declared sizes and limits).
     *
     * @param  resource  $handle
     * @param  array{entries: int, offset: int, size: int}  $directory
     * @return list<array{name: string, isDirectory: bool, countsAsFile: bool, method: int, flags: int, crc: int, compressedSize: int, uncompressedSize: int, offset: int}>
     */
    private function readCentralDirectory($handle, array $directory): array
    {
        $data = $this->read($handle, $directory['offset'], $directory['size']);
        $cursor = 0;
        $entries = [];
        $seen = [];
        $fileCount = 0;
        $declaredTotal = 0;

        for ($i = 0; $i < $directory['entries']; $i++) {
            if ($cursor + self::CENTRAL_HEADER_SIZE > $directory['size']) {
                throw SourceArchiveRejected::invalid('truncated_central_directory');
            }
            $header = $this->unpack(
                'Vsignature/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset',
                $data,
                $cursor,
            );
            if ($header['signature'] !== self::CENTRAL_HEADER_SIGNATURE) {
                throw SourceArchiveRejected::invalid('bad_central_header');
            }
            $variableEnd = $cursor + self::CENTRAL_HEADER_SIZE + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            if ($variableEnd > $directory['size']) {
                throw SourceArchiveRejected::invalid('truncated_central_directory');
            }
            $name = substr($data, $cursor + self::CENTRAL_HEADER_SIZE, $header['nameLength']);
            $extra = substr($data, $cursor + self::CENTRAL_HEADER_SIZE + $header['nameLength'], $header['extraLength']);
            $cursor = $variableEnd;

            [$compressedSize, $uncompressedSize, $offset, $disk] = $this->applyZip64Extra($header, $extra);

            if ($disk !== 0) {
                throw SourceArchiveRejected::invalid('multi_disk');
            }
            if (($header['flags'] & self::ENCRYPTION_FLAGS) !== 0) {
                throw SourceArchiveRejected::invalid('encrypted');
            }
            if ($header['method'] !== self::METHOD_STORED && $header['method'] !== self::METHOD_DEFLATED) {
                throw SourceArchiveRejected::invalid('unsupported_compression');
            }

            $isDirectory = $this->validateName($name);
            $this->validateType($header['madeBy'], $header['external'], $isDirectory);

            $path = $isDirectory ? substr($name, 0, -1) : $name;
            if (isset($seen[$path])) {
                throw SourceArchiveRejected::invalid('duplicate_entry');
            }
            $seen[$path] = $isDirectory;

            if ($isDirectory && $uncompressedSize !== 0) {
                throw SourceArchiveRejected::invalid('directory_with_content');
            }
            if ($header['method'] === self::METHOD_STORED && $compressedSize !== $uncompressedSize) {
                throw SourceArchiveRejected::invalid('stored_size_mismatch');
            }
            if ($uncompressedSize > $this->limits->singleFileBytes) {
                throw SourceArchiveRejected::fileTooLarge();
            }
            $declaredTotal += $uncompressedSize;
            if ($declaredTotal > $this->limits->uncompressedBytes) {
                throw SourceArchiveRejected::uncompressedSizeExceeded();
            }

            $countsAsFile = ! $isDirectory && ! $this->isArchiveMetadata($path);
            if ($countsAsFile && ++$fileCount > $this->limits->files) {
                throw SourceArchiveRejected::fileCountExceeded();
            }

            $entries[] = [
                'name' => $name,
                'isDirectory' => $isDirectory,
                'countsAsFile' => $countsAsFile,
                'method' => $header['method'],
                'flags' => $header['flags'],
                'crc' => $header['crc'],
                'compressedSize' => $compressedSize,
                'uncompressedSize' => $uncompressedSize,
                'offset' => $offset,
            ];
        }

        if ($cursor !== $directory['size']) {
            throw SourceArchiveRejected::invalid('trailing_central_directory_data');
        }

        // A path cannot be both a file and a directory ("a" and "a/b").
        foreach (array_keys($seen) as $path) {
            $parent = (string) $path;
            while (($slash = strrpos($parent, '/')) !== false) {
                $parent = substr($parent, 0, $slash);
                if (($seen[$parent] ?? true) === false) {
                    throw SourceArchiveRejected::invalid('file_directory_conflict');
                }
            }
        }

        return $entries;
    }

    /**
     * Replaces 0xFFFFFFFF/0xFFFF placeholders with the values from the ZIP64
     * extended information extra field (header ID 0x0001).
     *
     * @param  array<string, int>  $header
     * @return array{0: int, 1: int, 2: int, 3: int} compressed size, uncompressed size, local header offset, disk
     */
    private function applyZip64Extra(array $header, string $extra): array
    {
        $uncompressed = $header['uncompressedSize'];
        $compressed = $header['compressedSize'];
        $offset = $header['offset'];
        $disk = $header['disk'];

        $needed = [
            'uncompressed' => $uncompressed === 0xFFFFFFFF,
            'compressed' => $compressed === 0xFFFFFFFF,
            'offset' => $offset === 0xFFFFFFFF,
            'disk' => $disk === 0xFFFF,
        ];
        if (! in_array(true, $needed, true)) {
            return [$compressed, $uncompressed, $offset, $disk];
        }

        $field = null;
        $position = 0;
        while ($position + 4 <= strlen($extra)) {
            $block = $this->unpack('vid/vsize', $extra, $position);
            if ($position + 4 + $block['size'] > strlen($extra)) {
                throw SourceArchiveRejected::invalid('bad_extra_field');
            }
            if ($block['id'] === 0x0001) {
                $field = substr($extra, $position + 4, $block['size']);
                break;
            }
            $position += 4 + $block['size'];
        }
        if ($field === null) {
            throw SourceArchiveRejected::invalid('missing_zip64_extra');
        }

        $cursor = 0;
        $take = function (int $bytes) use ($field, &$cursor): int {
            if ($cursor + $bytes > strlen($field)) {
                throw SourceArchiveRejected::invalid('bad_zip64_extra');
            }
            $value = $this->unpack($bytes === 8 ? 'P' : 'V', $field, $cursor)[1];
            $cursor += $bytes;
            if ($value < 0) {
                throw SourceArchiveRejected::invalid('bad_zip64_extra');
            }

            return $value;
        };
        if ($needed['uncompressed']) {
            $uncompressed = $take(8);
        }
        if ($needed['compressed']) {
            $compressed = $take(8);
        }
        if ($needed['offset']) {
            $offset = $take(8);
        }
        if ($needed['disk']) {
            $disk = $take(4);
        }

        return [$compressed, $uncompressed, $offset, $disk];
    }

    /**
     * Rejects any path that is not a plain, relative, normalized UTF-8 path.
     *
     * @return bool whether the entry is a directory (name ends with "/")
     */
    private function validateName(string $name): bool
    {
        if ($name === '') {
            throw SourceArchiveRejected::invalid('empty_name');
        }
        if (strlen($name) > $this->limits->pathLength) {
            throw SourceArchiveRejected::unsafe('path_too_long');
        }
        if (! mb_check_encoding($name, 'UTF-8')) {
            throw SourceArchiveRejected::invalid('name_not_utf8');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw SourceArchiveRejected::unsafe('control_character');
        }
        if (str_contains($name, '\\')) {
            throw SourceArchiveRejected::unsafe('backslash');
        }
        if (str_starts_with($name, '/')) {
            throw SourceArchiveRejected::unsafe('absolute_path');
        }
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw SourceArchiveRejected::unsafe('drive_path');
        }

        $isDirectory = str_ends_with($name, '/');
        $path = $isDirectory ? substr($name, 0, -1) : $name;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw SourceArchiveRejected::unsafe('path_traversal');
            }
            if ($segment === '' || $segment === '.') {
                throw SourceArchiveRejected::unsafe('non_normalized_path');
            }
        }

        return $isDirectory;
    }

    /**
     * Unix permissions live in the high 16 bits of the external attributes.
     * Only regular files and directories are allowed.
     */
    private function validateType(int $madeBy, int $externalAttributes, bool $isDirectory): void
    {
        $host = $madeBy >> 8;
        if ($host !== self::HOST_UNIX && $host !== self::HOST_OSX) {
            return;
        }

        $type = ($externalAttributes >> 16) & self::UNIX_TYPE_MASK;
        if ($type === 0) {
            return;
        }
        if ($type === self::UNIX_SYMLINK) {
            throw SourceArchiveRejected::unsafe('symlink');
        }
        if ($type !== self::UNIX_REGULAR_FILE && $type !== self::UNIX_DIRECTORY) {
            throw SourceArchiveRejected::unsafe('special_file');
        }
        if (($type === self::UNIX_DIRECTORY) !== $isDirectory) {
            throw SourceArchiveRejected::invalid('type_name_mismatch');
        }
    }

    private function isArchiveMetadata(string $path): bool
    {
        return $path === self::MACOS_METADATA_DIRECTORY || str_starts_with($path, self::MACOS_METADATA_DIRECTORY.'/');
    }

    /**
     * Checks the layout (no prepended data, no overlaps, local headers agree
     * with the central directory) and streams every entry to verify its real
     * size and CRC-32.
     *
     * @param  resource  $handle
     * @param  list<array{name: string, isDirectory: bool, countsAsFile: bool, method: int, flags: int, crc: int, compressedSize: int, uncompressedSize: int, offset: int}>  $entries
     */
    private function verifyEntries($handle, array $entries, int $directoryOffset): ArchiveSummary
    {
        $byOffset = $entries;
        usort($byOffset, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        if ($byOffset[0]['offset'] !== 0) {
            throw SourceArchiveRejected::invalid('data_before_first_entry');
        }

        $previousEnd = 0;
        $total = 0;
        foreach ($byOffset as $entry) {
            if ($entry['offset'] < $previousEnd) {
                throw SourceArchiveRejected::unsafe('overlapping_entries');
            }
            if ($directoryOffset < $entry['offset'] + self::LOCAL_HEADER_SIZE) {
                throw SourceArchiveRejected::invalid('entry_outside_archive');
            }

            $local = $this->unpack(
                'Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength',
                $this->read($handle, $entry['offset'], self::LOCAL_HEADER_SIZE),
            );
            if ($local['signature'] !== self::LOCAL_HEADER_SIGNATURE) {
                throw SourceArchiveRejected::invalid('bad_local_header');
            }
            $dataStart = $entry['offset'] + self::LOCAL_HEADER_SIZE + $local['nameLength'] + $local['extraLength'];
            $dataEnd = $dataStart + $entry['compressedSize'];
            if ($dataEnd > $directoryOffset) {
                throw SourceArchiveRejected::invalid('entry_outside_archive');
            }
            if ($local['method'] !== $entry['method']
                || ($local['flags'] & self::ENCRYPTION_FLAGS) !== 0
                || $this->read($handle, $entry['offset'] + self::LOCAL_HEADER_SIZE, $local['nameLength']) !== $entry['name']) {
                throw SourceArchiveRejected::unsafe('local_header_mismatch');
            }

            $this->verifyData($handle, $dataStart, $entry);
            $total += $entry['uncompressedSize'];
            // Bit 3: a data descriptor follows the data; the next entry starts after it.
            $previousEnd = $dataEnd;
        }

        $files = array_values(array_map(
            static fn (array $entry): string => $entry['name'],
            array_filter($entries, static fn (array $entry): bool => $entry['countsAsFile']),
        ));
        if ($files === []) {
            throw SourceArchiveRejected::invalid('no_files');
        }

        return new ArchiveSummary(
            entryCount: count($entries),
            fileCount: count($files),
            directoryCount: count(array_filter($entries, static fn (array $entry): bool => $entry['isDirectory'])),
            uncompressedBytes: $total,
            filePaths: $files,
        );
    }

    /**
     * Streams an entry's data: the decompressed byte count must equal the
     * declared size (never more), and the CRC-32 must match.
     *
     * @param  resource  $handle
     * @param  array{name: string, isDirectory: bool, countsAsFile: bool, method: int, flags: int, crc: int, compressedSize: int, uncompressedSize: int, offset: int}  $entry
     */
    private function verifyData($handle, int $dataStart, array $entry): void
    {
        $inflate = null;
        if ($entry['method'] === self::METHOD_DEFLATED) {
            $inflate = inflate_init(ZLIB_ENCODING_RAW);
            if ($inflate === false) {
                throw SourceArchiveRejected::invalid('inflate_unavailable');
            }
        }

        $crc = hash_init('crc32b');
        $remaining = $entry['compressedSize'];
        $produced = 0;

        if (fseek($handle, $dataStart) !== 0) {
            throw SourceArchiveRejected::invalid('truncated_entry');
        }

        while ($remaining > 0) {
            $chunk = fread($handle, min(self::READ_CHUNK_BYTES, $remaining));
            if ($chunk === false || $chunk === '') {
                throw SourceArchiveRejected::invalid('truncated_entry');
            }
            $remaining -= strlen($chunk);

            if ($inflate !== null) {
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    throw SourceArchiveRejected::invalid('trailing_entry_data');
                }
                $output = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
                if ($output === false) {
                    throw SourceArchiveRejected::invalid('corrupt_entry');
                }
            } else {
                $output = $chunk;
            }

            $produced += strlen($output);
            if ($produced > $entry['uncompressedSize']) {
                // The header understated the size: a decompression bomb.
                throw SourceArchiveRejected::unsafe('size_understated');
            }
            hash_update($crc, $output);
        }

        if ($inflate !== null) {
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                $output = @inflate_add($inflate, '', ZLIB_FINISH);
                if ($output === false || inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                    throw SourceArchiveRejected::invalid('corrupt_entry');
                }
                $produced += strlen($output);
                if ($produced > $entry['uncompressedSize']) {
                    throw SourceArchiveRejected::unsafe('size_understated');
                }
                hash_update($crc, $output);
            }
            if (inflate_get_read_len($inflate) !== $entry['compressedSize']) {
                throw SourceArchiveRejected::invalid('trailing_entry_data');
            }
        }

        if ($produced !== $entry['uncompressedSize']) {
            throw SourceArchiveRejected::invalid('size_mismatch');
        }
        if (hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
            throw SourceArchiveRejected::invalid('crc_mismatch');
        }
    }

    /**
     * @param  resource  $handle
     */
    private function read($handle, int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        $data = stream_get_contents($handle, $length, $offset);
        if ($data === false || strlen($data) !== $length) {
            throw SourceArchiveRejected::invalid('truncated');
        }

        return $data;
    }

    /**
     * @return array<int|string, int>
     */
    private function unpack(string $format, string $data, int $offset = 0): array
    {
        $values = @unpack($format, $data, $offset);
        if ($values === false) {
            throw SourceArchiveRejected::invalid('truncated');
        }

        return $values;
    }
}
