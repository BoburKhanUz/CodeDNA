<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

/**
 * Invitation tokens (docs/teams/invitations.md#tokens): 32 random bytes
 * (256 bits) from the CSPRNG, base64url without padding (43 characters).
 * Only the SHA-256 is stored; a token is looked up by its hash, so the
 * stored value is never compared to client input.
 */
final class InvitationToken
{
    public const PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    /** Hours an invitation stays valid. */
    public const TTL_HOURS = 72;

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function wellFormed(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }

    /** The canonical form emails are compared in: trimmed, lowercase. */
    public static function canonicalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
