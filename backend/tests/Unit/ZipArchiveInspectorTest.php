<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\SourceArchiveRejected;
use App\Http\Errors\ErrorCode;
use App\Support\Sources\SourceArchiveLimits;
use App\Support\Sources\ZipArchiveInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealWorldZips;
use Tests\Support\ZipBuilder;

final class ZipArchiveInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function inspector(?SourceArchiveLimits $limits = null): ZipArchiveInspector
    {
        return new ZipArchiveInspector($limits ?? new SourceArchiveLimits(
            archiveBytes: 1024 * 1024,
            uncompressedBytes: 4 * 1024 * 1024,
            files: 50,
            singleFileBytes: 1024 * 1024,
            pathLength: 200,
        ));
    }

    private function save(ZipBuilder|string $zip): string
    {
        $path = $zip instanceof ZipBuilder ? $zip->save() : RealWorldZips::save($zip);
        $this->paths[] = $path;

        return $path;
    }

    private function assertRejected(string $path, ErrorCode $code, string $reason, ?ZipArchiveInspector $inspector = null): void
    {
        try {
            ($inspector ?? $this->inspector())->inspect($path);
            $this->fail("Expected the archive to be rejected ({$reason}).");
        } catch (SourceArchiveRejected $rejection) {
            $this->assertSame($code, $rejection->errorCode, "reason: {$rejection->reason}");
            $this->assertSame($reason, $rejection->reason);
            $this->assertSame($code->defaultMessage(), $rejection->getMessage());
        }
    }

    public function test_accepts_a_normal_archive_and_counts_only_files(): void
    {
        $summary = $this->inspector()->inspect($this->save((new ZipBuilder)
            ->directory('src/')
            ->directory('src/app/')
            ->file('src/app/main.php', "<?php\necho 'hello';\n")
            ->file('src/README.md', str_repeat('docs ', 200), ['method' => 0])
            ->file('composer.json', '{}')));

        $this->assertSame(5, $summary->entryCount);
        $this->assertSame(3, $summary->fileCount);
        $this->assertSame(2, $summary->directoryCount);
        $this->assertSame(strlen("<?php\necho 'hello';\n") + 1000 + 2, $summary->uncompressedBytes);
        $this->assertSame(['src/app/main.php', 'src/README.md', 'composer.json'], $summary->filePaths);
    }

    #[DataProvider('realWorldArchives')]
    public function test_accepts_archives_written_by_common_tools(string $base64): void
    {
        $summary = $this->inspector()->inspect($this->save($base64));

        $this->assertSame(3, $summary->fileCount);
        $this->assertEqualsCanonicalizing(['src/app/main.php', 'src/app/tool.py', 'src/README.md'], $summary->filePaths);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function realWorldArchives(): array
    {
        return RealWorldZips::all();
    }

    public function test_accepts_data_descriptors_zip64_records_and_comments(): void
    {
        $summary = $this->inspector()->inspect($this->save((new ZipBuilder)
            ->zip64()
            ->comment('built by a streaming writer')
            ->file('a.py', 'print(1)', ['data_descriptor' => true])
            ->file('b.py', 'print(2)')));

        $this->assertSame(2, $summary->fileCount);
    }

    public function test_macos_resource_forks_are_not_counted_as_source_files(): void
    {
        $summary = $this->inspector()->inspect($this->save((new ZipBuilder)
            ->file('app.js', 'x')
            ->directory('__MACOSX/')
            ->file('__MACOSX/._app.js', 'resource fork')));

        $this->assertSame(1, $summary->fileCount);
        $this->assertSame(['app.js'], $summary->filePaths);
    }

    /**
     * @return array<string, array{0: ZipBuilder, 1: ErrorCode, 2: string}>
     */
    public static function unsafeArchives(): array
    {
        $unsafe = ErrorCode::SourceArchiveUnsafe;

        return [
            'parent traversal' => [(new ZipBuilder)->file('../evil.php', 'x'), $unsafe, 'path_traversal'],
            'nested traversal' => [(new ZipBuilder)->file('src/../../evil.php', 'x'), $unsafe, 'path_traversal'],
            'absolute path' => [(new ZipBuilder)->file('/absolute/path.php', 'x'), $unsafe, 'absolute_path'],
            'windows drive' => [(new ZipBuilder)->file('C:/absolute/path.php', 'x'), $unsafe, 'drive_path'],
            'windows separators' => [(new ZipBuilder)->file('C:\\absolute\\path.php', 'x'), $unsafe, 'backslash'],
            'backslash traversal' => [(new ZipBuilder)->file('..\\evil.php', 'x'), $unsafe, 'backslash'],
            'dot segment' => [(new ZipBuilder)->file('./a.php', 'x'), $unsafe, 'non_normalized_path'],
            'empty segment' => [(new ZipBuilder)->file('a//b.php', 'x'), $unsafe, 'non_normalized_path'],
            'control character' => [(new ZipBuilder)->file("a\nb.php", 'x'), $unsafe, 'control_character'],
            'nul byte' => [(new ZipBuilder)->file("a.php\0.txt", 'x'), $unsafe, 'control_character'],
            'symlink' => [(new ZipBuilder)->file('a.php', 'x')->symlink('link', '/etc/passwd'), $unsafe, 'symlink'],
            'device file' => [(new ZipBuilder)->entry('dev', '', ['method' => 0, 'external' => 0o020644 << 16]), $unsafe, 'special_file'],
            'fifo' => [(new ZipBuilder)->entry('pipe', '', ['method' => 0, 'external' => 0o010644 << 16]), $unsafe, 'special_file'],
            'path too long' => [(new ZipBuilder)->file(str_repeat('a/', 100).'x.php', 'x'), $unsafe, 'path_too_long'],
            'local header names another file' => [(new ZipBuilder)->file('safe.php', 'x', ['local_name' => '../e.php']), $unsafe, 'local_header_mismatch'],
            'understated size (bomb)' => [(new ZipBuilder)->file('bomb.txt', str_repeat('A', 500_000), ['declared_size' => 1000]), $unsafe, 'size_understated'],
            'overlapping entries' => [(new ZipBuilder)->file('a.txt', 'aaaa')->file('b.txt', 'bbbb', ['offset' => 0, 'local_name' => 'a.txt']), $unsafe, 'overlapping_entries'],
        ];
    }

    #[DataProvider('unsafeArchives')]
    public function test_rejects_unsafe_archives(ZipBuilder $zip, ErrorCode $code, string $reason): void
    {
        $this->assertRejected($this->save($zip), $code, $reason);
    }

    /**
     * @return array<string, array{0: ZipBuilder|string, 1: ErrorCode, 2: string}>
     */
    public static function invalidArchives(): array
    {
        $invalid = ErrorCode::SourceArchiveInvalid;
        $tarGz = (string) gzencode(str_pad('src/main.php', 512, "\0").str_repeat("\0", 1024));
        $hidden = substr((new ZipBuilder)->file('hidden.php', '<?php system($_GET[1]);', ['method' => 0])->build(), 0, 30 + strlen('hidden.php') + 23);

        return [
            'gzip/tar' => [$tarGz, $invalid, 'not_a_zip'],
            'plain text named .zip' => ['this is not an archive, just text pretending to be one', $invalid, 'not_a_zip'],
            'html' => ['<html><script>alert(1)</script></html>'.str_repeat(' ', 40), $invalid, 'not_a_zip'],
            'tiny' => ['PK', $invalid, 'too_small'],
            'truncated' => [substr((new ZipBuilder)->file('a.php', str_repeat('x', 1000))->build(), 0, -10), $invalid, 'no_end_record'],
            'only directories' => [(new ZipBuilder)->directory('src/'), $invalid, 'no_files'],
            'encrypted' => [(new ZipBuilder)->file('a.php', 'x', ['flags' => 0x0001]), $invalid, 'encrypted'],
            'unsupported compression' => [(new ZipBuilder)->file('a.php', 'x', ['method' => 12]), $invalid, 'unsupported_compression'],
            'duplicate entry' => [(new ZipBuilder)->file('a.php', 'x')->file('a.php', 'y'), $invalid, 'duplicate_entry'],
            'file and directory with one name' => [(new ZipBuilder)->file('src', 'x')->file('src/a.php', 'y'), $invalid, 'file_directory_conflict'],
            'directory with content' => [(new ZipBuilder)->entry('dir/', 'content', ['method' => 0, 'external' => 0o040755 << 16]), $invalid, 'directory_with_content'],
            'directory type on a file name' => [(new ZipBuilder)->entry('file', '', ['method' => 0, 'external' => 0o040755 << 16]), $invalid, 'type_name_mismatch'],
            'shell stub before the archive' => [(new ZipBuilder)->prepend("#!/bin/sh\nexit 0\n")->file('a.php', 'x'), $invalid, 'not_a_zip'],
            'data before the first entry' => [(new ZipBuilder)->prepend("PK\x03\x04 hidden stub")->file('a.php', 'x'), $invalid, 'data_before_first_entry'],
            // A local entry no central record lists: a reader that walks local headers would see an extra file.
            'hidden entry between entries' => [(new ZipBuilder)->file('a.php', 'x', ['trailing' => $hidden])->file('b.php', 'y'), $invalid, 'data_between_entries'],
            'hidden entry before the central directory' => [(new ZipBuilder)->file('a.php', 'x', ['trailing' => $hidden]), $invalid, 'data_between_entries'],
            'bytes after a data descriptor' => [(new ZipBuilder)->file('a.php', 'x', ['data_descriptor' => true, 'trailing' => str_repeat("\0", 13)])->file('b.php', 'y'), $invalid, 'data_between_entries'],
            'crc mismatch' => [(new ZipBuilder)->file('a.php', 'hello', ['crc' => 12345]), $invalid, 'crc_mismatch'],
            'overstated size' => [(new ZipBuilder)->file('a.php', 'hello', ['declared_size' => 10]), $invalid, 'size_mismatch'],
            'stored size mismatch' => [(new ZipBuilder)->file('a.php', 'hello', ['method' => 0, 'declared_compressed_size' => 3]), $invalid, 'stored_size_mismatch'],
            'name not utf-8' => [(new ZipBuilder)->file("caf\xE9.php", 'x'), $invalid, 'name_not_utf8'],
        ];
    }

    #[DataProvider('invalidArchives')]
    public function test_rejects_invalid_archives(ZipBuilder|string $zip, ErrorCode $code, string $reason): void
    {
        if (is_string($zip)) {
            $path = (string) tempnam(sys_get_temp_dir(), 'codedna-zip-');
            file_put_contents($path, $zip);
            $this->paths[] = $path;
        } else {
            $path = $this->save($zip);
        }

        $this->assertRejected($path, $code, $reason);
    }

    public function test_enforces_the_archive_size_limit(): void
    {
        $path = $this->save((new ZipBuilder)->file('a.txt', random_bytes(2048), ['method' => 0]));
        $limits = new SourceArchiveLimits(archiveBytes: 1024, uncompressedBytes: 10_000, files: 10, singleFileBytes: 10_000, pathLength: 100);

        $this->assertRejected($path, ErrorCode::SourceArchiveTooLarge, 'archive_too_large', $this->inspector($limits));
    }

    public function test_enforces_the_total_uncompressed_size_limit(): void
    {
        // Highly compressible: tiny archive, large content.
        $path = $this->save((new ZipBuilder)->file('a.txt', str_repeat('a', 600_000))->file('b.txt', str_repeat('b', 600_000)));
        $limits = new SourceArchiveLimits(archiveBytes: 1024 * 1024, uncompressedBytes: 1_000_000, files: 10, singleFileBytes: 1_000_000, pathLength: 100);

        $this->assertRejected($path, ErrorCode::SourceUncompressedSizeExceeded, 'uncompressed_size_exceeded', $this->inspector($limits));
    }

    public function test_rejects_a_huge_declared_size_before_decompressing_anything(): void
    {
        $path = $this->save((new ZipBuilder)->zip64()->file('huge.bin', 'x', ['declared_size' => 50 * 1024 ** 3]));

        $this->assertRejected($path, ErrorCode::SourceFileTooLarge, 'file_too_large');
    }

    public function test_enforces_the_single_file_size_limit(): void
    {
        $path = $this->save((new ZipBuilder)->file('small.txt', 'x')->file('big.txt', str_repeat('z', 3000)));
        $limits = new SourceArchiveLimits(archiveBytes: 1024 * 1024, uncompressedBytes: 1_000_000, files: 10, singleFileBytes: 2000, pathLength: 100);

        $this->assertRejected($path, ErrorCode::SourceFileTooLarge, 'file_too_large', $this->inspector($limits));
    }

    public function test_enforces_the_file_count_limit(): void
    {
        $zip = new ZipBuilder;
        for ($i = 0; $i < 11; $i++) {
            $zip->file("src/file{$i}.php", 'x');
        }
        $limits = new SourceArchiveLimits(archiveBytes: 1024 * 1024, uncompressedBytes: 1_000_000, files: 10, singleFileBytes: 1000, pathLength: 100);

        $this->assertRejected($this->save($zip), ErrorCode::SourceFileCountExceeded, 'file_count_exceeded', $this->inspector($limits));
    }

    public function test_bounds_the_total_number_of_entries_including_directories(): void
    {
        $zip = (new ZipBuilder)->file('a.php', 'x');
        for ($i = 0; $i < 20; $i++) {
            $zip->directory("dir{$i}/");
        }
        $limits = new SourceArchiveLimits(archiveBytes: 1024 * 1024, uncompressedBytes: 1_000_000, files: 10, singleFileBytes: 1000, pathLength: 100);

        $this->assertRejected($this->save($zip), ErrorCode::SourceFileCountExceeded, 'file_count_exceeded', $this->inspector($limits));
    }

    public function test_exactly_at_the_limits_is_accepted(): void
    {
        $zip = new ZipBuilder;
        for ($i = 0; $i < 10; $i++) {
            $zip->file("f{$i}.txt", str_repeat('x', 100));
        }
        $limits = new SourceArchiveLimits(archiveBytes: 1024 * 1024, uncompressedBytes: 1000, files: 10, singleFileBytes: 100, pathLength: 6);

        $this->assertSame(10, $this->inspector($limits)->inspect($this->save($zip))->fileCount);
    }

    public function test_rejection_messages_never_contain_entry_names(): void
    {
        try {
            $this->inspector()->inspect($this->save((new ZipBuilder)->file('../secret-name-<script>.php', 'x')));
            $this->fail('Expected rejection.');
        } catch (SourceArchiveRejected $rejection) {
            $this->assertStringNotContainsString('secret-name', $rejection->getMessage());
            $this->assertStringNotContainsString('secret-name', $rejection->reason);
        }
    }
}
