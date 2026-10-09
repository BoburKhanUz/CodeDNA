<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Enterprise license fixtures for tests only (Phase 27). A throwaway Ed25519
 * key pair derived from a fixed, public seed: deterministic, never trusted
 * outside a test (config/license.php ships without it, and tests are not part
 * of any image). Not a license issuer: there is no production counterpart in
 * this repository by design (docs/enterprise/licensing.md#issuance).
 */
final class LicenseFixtures
{
    public const KEY_ID = 'test-2026-01';

    public const HOST = 'app.codedna.example';

    /** @return array{public: string, secret: string} */
    public static function keyPair(string $seedByte = "\x01"): array
    {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat($seedByte, SODIUM_CRYPTO_SIGN_SEEDBYTES));

        return ['public' => sodium_crypto_sign_publickey($pair), 'secret' => sodium_crypto_sign_secretkey($pair)];
    }

    /** @return array<string, string> the keyring config/license.php would hold */
    public static function trustedKeys(): array
    {
        return [self::KEY_ID => base64_encode(self::keyPair()['public'])];
    }

    /**
     * Valid v1 claims (2026-01-01 to 2027-01-01, for this test installation).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function claims(array $overrides = []): array
    {
        return array_replace([
            'schema' => 'codedna.license.v1',
            'license_id' => 'lic-0001',
            'licensee' => 'Example Corp',
            'issued_at' => '2026-01-01T00:00:00Z',
            'not_before' => '2026-01-01T00:00:00Z',
            'expires_at' => '2027-01-01T00:00:00Z',
            'installation' => ['app_url_host' => self::HOST],
            'entitlements' => ['organization_plan' => 'TEAM_READY', 'organization_seats' => 50],
        ], $overrides);
    }

    /**
     * A license document signing $payload (claims, or raw bytes) with the test key.
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, mixed>  $envelope  envelope fields to override
     */
    public static function document(array|string $payload = [], array $envelope = [], string $seedByte = "\x01"): string
    {
        $bytes = is_string($payload) ? $payload : json_encode(self::claims($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($bytes, self::keyPair($seedByte)['secret']);

        return json_encode(array_replace([
            'format' => 'codedna-license',
            'version' => 1,
            'key_id' => self::KEY_ID,
            'payload' => self::base64url($bytes),
            'signature' => self::base64url($signature),
        ], $envelope), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Installs $document as the application's license (a temporary file) and
     * trusts the test key, for the rest of the test.
     */
    public static function install(string $document): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'license');
        file_put_contents($path, $document);
        config([
            'codedna.enterprise.license_path' => $path,
            'license.trusted_keys' => self::trustedKeys(),
            'app.url' => 'https://'.self::HOST,
        ]);
        // The edition is scoped: forget anything read before the license changed.
        app()->forgetScopedInstances();

        return $path;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
