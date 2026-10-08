<?php

declare(strict_types=1);

namespace App\Http\Pagination;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;

/**
 * Opaque, tamper-evident cursors for keyset pagination (Phase 26,
 * docs/api/README.md#pagination).
 *
 * A cursor carries the sort key of the row it points at, the direction, and
 * the list it belongs to (for example "audit:<organization id>"), as base64url
 * JSON followed by an HMAC-SHA256 tag keyed from APP_KEY. It never contains
 * SQL, and its values are only ever bound as query parameters. A cursor that
 * was altered, belongs to another list, or was signed with another key is
 * rejected as a validation error (422), never interpreted.
 */
final readonly class CursorCodec
{
    private const MAX_VALUES = 4;

    private const MAX_VALUE_LENGTH = 64;

    public function __construct(private Repository $config) {}

    /**
     * @param  list<string>  $values
     */
    public function encode(string $list, array $values, CursorDirection $direction): string
    {
        $payload = self::base64(json_encode(['l' => $list, 'v' => $values, 'd' => $direction->value], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::base64($this->tag($payload));
    }

    /**
     * @return array{list<string>, CursorDirection}
     */
    public function decode(string $list, string $cursor, int $values): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) === 2 && hash_equals(self::base64($this->tag($parts[0])), $parts[1])) {
            $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
            $direction = is_array($data) ? CursorDirection::tryFrom((string) ($data['d'] ?? '')) : null;
            $decoded = is_array($data) && is_array($data['v'] ?? null) ? array_values($data['v']) : [];
            $valid = $direction !== null
                && ($data['l'] ?? null) === $list
                && count($decoded) === $values && $values <= self::MAX_VALUES
                && $decoded === array_filter($decoded, static fn (mixed $v): bool => is_string($v) && strlen($v) <= self::MAX_VALUE_LENGTH);
            if ($valid) {
                return [$decoded, $direction];
            }
        }

        throw ValidationException::withMessages(['cursor' => 'The cursor is invalid or has expired.']);
    }

    private function tag(string $payload): string
    {
        // A key of its own, derived from APP_KEY: cursors can never be confused with other signed values.
        return substr(hash_hmac('sha256', $payload, 'codedna.cursor.v1|'.(string) $this->config->get('app.key'), true), 0, 16);
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
