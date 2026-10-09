<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use Carbon\CarbonImmutable;
use JsonException;

/**
 * Offline verification of an enterprise license (Phase 27,
 * docs/enterprise/licensing.md).
 *
 * A license document is a small JSON envelope:
 *
 *   {"format": "codedna-license", "version": 1, "key_id": "...",
 *    "payload": "<base64url of the claims JSON>", "signature": "<base64url>"}
 *
 * The Ed25519 signature covers the payload bytes exactly as transmitted, so
 * no canonicalization is involved. The checks run in this order, and the
 * claims are read only after the signature has been verified:
 *
 *   1. envelope shape and version   -> MALFORMED / UNSUPPORTED_VERSION
 *   2. a trusted key id             -> UNKNOWN_KEY
 *   3. the signature                -> INVALID_SIGNATURE
 *   4. the claims (strict schema)   -> MALFORMED / UNSUPPORTED_VERSION
 *   5. the installation (APP_URL)   -> WRONG_INSTALLATION
 *   6. the validity period          -> NOT_YET_VALID / EXPIRED
 *
 * Pure: no I/O, no clock and no configuration of its own; the caller passes
 * the trusted public keys, the installation host and the time.
 */
final class LicenseVerifier
{
    public const FORMAT = 'codedna-license';

    public const VERSION = 1;

    public const SCHEMA = 'codedna.license.v1';

    /** Plans a v1 license may put organizations on: the reserved team plan only. */
    public const ORGANIZATION_PLANS = ['TEAM_READY'];

    public const MAX_SEATS = 100_000;

    private const ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private const HOST = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /**
     * @param  array<string, string>  $trustedKeys  key id => base64 Ed25519 public key
     */
    public function verify(string $document, array $trustedKeys, string $installationHost, CarbonImmutable $now): LicenseVerification
    {
        if (trim($document) === '') {
            return new LicenseVerification(LicenseStatus::Absent);
        }

        $envelope = self::object($document);
        if ($envelope === null || ! self::keysAre($envelope, ['format', 'version', 'key_id', 'payload', 'signature']) || $envelope['format'] !== self::FORMAT) {
            return new LicenseVerification(LicenseStatus::Malformed);
        }
        if ($envelope['version'] !== self::VERSION) {
            return new LicenseVerification(LicenseStatus::UnsupportedVersion);
        }
        if (! is_string($envelope['key_id']) || preg_match(self::ID, $envelope['key_id']) !== 1 || ! is_string($envelope['payload']) || ! is_string($envelope['signature'])) {
            return new LicenseVerification(LicenseStatus::Malformed);
        }

        $publicKey = self::publicKey($trustedKeys[$envelope['key_id']] ?? null);
        if ($publicKey === null) {
            return new LicenseVerification(LicenseStatus::UnknownKey);
        }
        $payload = self::base64url($envelope['payload']);
        $signature = self::base64url($envelope['signature']);
        if ($payload === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return new LicenseVerification(LicenseStatus::Malformed);
        }
        if (! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            return new LicenseVerification(LicenseStatus::InvalidSignature);
        }

        // Signed by a trusted key: now (and only now) read the claims.
        $claims = self::object($payload);
        if ($claims === null || ! array_key_exists('schema', $claims)) {
            return new LicenseVerification(LicenseStatus::Malformed);
        }
        if ($claims['schema'] !== self::SCHEMA) {
            return new LicenseVerification(LicenseStatus::UnsupportedVersion);
        }
        $license = self::license($claims, $envelope['key_id']);
        if ($license === null) {
            return new LicenseVerification(LicenseStatus::Malformed);
        }

        $status = match (true) {
            $license->installationHost !== strtolower($installationHost) => LicenseStatus::WrongInstallation,
            $now->lessThan($license->notBefore) => LicenseStatus::NotYetValid,
            $now->greaterThanOrEqualTo($license->expiresAt) => LicenseStatus::Expired,
            default => LicenseStatus::Valid,
        };

        return new LicenseVerification($status, $license);
    }

    /** @param  array<mixed>  $claims */
    private static function license(array $claims, string $keyId): ?License
    {
        if (! self::keysAre($claims, ['schema', 'license_id', 'licensee', 'issued_at', 'not_before', 'expires_at', 'installation', 'entitlements'])) {
            return null;
        }
        $installation = $claims['installation'];
        $entitlements = $claims['entitlements'];
        if (! is_array($installation) || ! self::keysAre($installation, ['app_url_host'])
            || ! is_array($entitlements) || ! self::keysAre($entitlements, ['organization_plan', 'organization_seats'])) {
            return null;
        }
        $licensee = $claims['licensee'];
        $host = $installation['app_url_host'];
        $plan = $entitlements['organization_plan'];
        $seats = $entitlements['organization_seats'];
        $issued = self::time($claims['issued_at']);
        $notBefore = self::time($claims['not_before']);
        $expires = self::time($claims['expires_at']);
        $valid = is_string($claims['license_id']) && preg_match(self::ID, $claims['license_id']) === 1
            && is_string($licensee) && trim($licensee) === $licensee && $licensee !== '' && mb_strlen($licensee) <= 200 && preg_match('/\p{C}/u', $licensee) !== 1
            && is_string($host) && preg_match(self::HOST, $host) === 1
            && is_string($plan) && in_array($plan, self::ORGANIZATION_PLANS, true)
            && is_int($seats) && $seats >= 1 && $seats <= self::MAX_SEATS
            && $issued !== null && $notBefore !== null && $expires !== null
            && $issued->lessThanOrEqualTo($expires) && $notBefore->lessThan($expires);

        return $valid ? new License((string) $claims['license_id'], (string) $licensee, $keyId, $issued, $notBefore, $expires, (string) $host, (string) $plan, (int) $seats) : null;
    }

    /**
     * Exactly these keys, in any order: an unknown claim is refused rather
     * than ignored, so a newer license is never half-understood.
     *
     * @param  array<mixed>  $value
     * @param  list<string>  $keys
     */
    private static function keysAre(array $value, array $keys): bool
    {
        $actual = array_map('strval', array_keys($value));
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    /** @return array<mixed>|null a JSON object (not a list), or null */
    private static function object(string $json): ?array
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : null;
    }

    private static function base64url(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }

    private static function publicKey(mixed $encoded): ?string
    {
        if (! is_string($encoded)) {
            return null;
        }
        $key = base64_decode($encoded, true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::TIME, $value) !== 1) {
            return null;
        }
        $time = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');

        // Reject impossible dates that PHP would roll over (e.g. 2026-02-30).
        return $time instanceof CarbonImmutable && $time->format('Y-m-d\TH:i:s\Z') === $value ? $time : null;
    }
}
