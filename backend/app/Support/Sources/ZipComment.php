<?php

declare(strict_types=1);

namespace App\Support\Sources;

/**
 * Reads the archive comment of a ZIP that ZipArchiveInspector has already
 * accepted (so its end record is known to be well formed). GitHub archives
 * carry the commit ID there (git archive). At most 64 KiB is read.
 */
final class ZipComment
{
    private const END_SIGNATURE = "PK\x05\x06";

    private const END_RECORD_SIZE = 22;

    public static function read(string $path): ?string
    {
        $size = @filesize($path);
        if ($size === false || $size < self::END_RECORD_SIZE) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $length = min($size, self::END_RECORD_SIZE + 0xFFFF);
            fseek($handle, $size - $length);
            $tail = (string) fread($handle, $length);
        } finally {
            fclose($handle);
        }
        for ($position = strrpos($tail, self::END_SIGNATURE); $position !== false; $position = $position > 0 ? strrpos(substr($tail, 0, $position), self::END_SIGNATURE) : false) {
            $commentLength = unpack('v', $tail, $position + 20)[1] ?? -1;
            if ($position + self::END_RECORD_SIZE + $commentLength === strlen($tail)) {
                return $commentLength === 0 ? null : substr($tail, $position + self::END_RECORD_SIZE, $commentLength);
            }
        }

        return null;
    }
}
