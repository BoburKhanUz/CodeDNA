<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Writes ZIP archives byte by byte for tests, including deliberately broken
 * or malicious ones (traversal names, symlinks, understated sizes, overlaps).
 * The backend container has no ext-zip, and the application never needs it.
 *
 * Nothing written here is ever extracted or executed.
 */
final class ZipBuilder
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    private string $prefix = '';

    private string $comment = '';

    private bool $zip64 = false;

    /**
     * @param  array<string, mixed>  $options  see entry()
     */
    public function file(string $name, string $content, array $options = []): self
    {
        return $this->entry($name, $content, $options);
    }

    public function directory(string $name): self
    {
        return $this->entry($name, '', ['method' => 0, 'external' => (0o040755 << 16) | 0x10]);
    }

    public function symlink(string $name, string $target): self
    {
        return $this->entry($name, $target, ['method' => 0, 'external' => 0o120777 << 16]);
    }

    /**
     * Options: method (0 stored, 8 deflated; default 8), declared_size,
     * declared_compressed_size, crc, made_by (default Unix), external,
     * flags, data_descriptor (bool), local_name, offset (overrides the
     * recorded local header offset), trailing (bytes written after the
     * entry's data and descriptor, which no central record points to).
     *
     * @param  array<string, mixed>  $options
     */
    public function entry(string $name, string $content, array $options = []): self
    {
        $this->entries[] = ['name' => $name, 'content' => $content, ...$options];

        return $this;
    }

    /** Bytes before the first entry (e.g. a self-extractor stub). */
    public function prepend(string $bytes): self
    {
        $this->prefix = $bytes;

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /** Write ZIP64 records and extra fields, as streaming writers do. */
    public function zip64(): self
    {
        $this->zip64 = true;

        return $this;
    }

    public function build(): string
    {
        $body = $this->prefix;
        $central = '';

        foreach ($this->entries as $entry) {
            $method = (int) ($entry['method'] ?? 8);
            $content = (string) $entry['content'];
            $data = $method === 8 ? (string) gzdeflate($content, 6, ZLIB_ENCODING_RAW) : $content;
            $crc = (int) ($entry['crc'] ?? crc32($content));
            $size = (int) ($entry['declared_size'] ?? strlen($content));
            $compressedSize = (int) ($entry['declared_compressed_size'] ?? strlen($data));
            $descriptor = (bool) ($entry['data_descriptor'] ?? false);
            $flags = (int) ($entry['flags'] ?? 0) | 0x0800 | ($descriptor ? 0x0008 : 0);
            $madeBy = (int) ($entry['made_by'] ?? (3 << 8) | 20);
            $external = (int) ($entry['external'] ?? 0o100644 << 16);
            $name = (string) $entry['name'];
            $localName = (string) ($entry['local_name'] ?? $name);
            $offset = (int) ($entry['offset'] ?? strlen($body));

            $localSizes = $descriptor ? [0, 0, 0] : [$crc, $compressedSize, $size];
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 0x5B21, ...[...$localSizes, strlen($localName), 0])
                .$localName.$data;
            if ($descriptor) {
                $body .= pack('VVVV', 0x08074B50, $crc, $compressedSize, $size);
            }
            $body .= (string) ($entry['trailing'] ?? '');

            $extra = '';
            [$cdCompressed, $cdSize, $cdOffset] = [$compressedSize, $size, $offset];
            if ($this->zip64) {
                $extra = pack('vvPPP', 0x0001, 24, $size, $compressedSize, $offset);
                [$cdCompressed, $cdSize, $cdOffset] = [0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF];
            }

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50, $madeBy, 20, $flags, $method, 0, 0x5B21,
                $crc, $cdCompressed, $cdSize, strlen($name), strlen($extra), 0, 0, 0, $external, $cdOffset,
            ).$name.$extra;
        }

        $count = count($this->entries);
        $centralOffset = strlen($body);
        $archive = $body.$central;

        if ($this->zip64) {
            $recordOffset = strlen($archive);
            $archive .= pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $count, $count, strlen($central), $centralOffset);
            $archive .= pack('VVPV', 0x07064B50, 0, $recordOffset, 1);

            return $archive.pack('VvvvvVVv', 0x06054B50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, strlen($this->comment)).$this->comment;
        }

        return $archive.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), $centralOffset, strlen($this->comment))
            .$this->comment;
    }

    /** Writes the archive to a new temporary file and returns its path. */
    public function save(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'codedna-zip-');
        file_put_contents($path, $this->build());

        return $path;
    }
}
