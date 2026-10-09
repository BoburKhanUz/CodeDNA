<?php

declare(strict_types=1);

namespace Tests\Unit\Enterprise;

use App\Services\Enterprise\LicenseStatus;
use App\Services\Enterprise\LicenseVerification;
use App\Services\Enterprise\LicenseVerifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LicenseFixtures;

/**
 * Offline license verification (Phase 27, docs/enterprise/licensing.md):
 * every way a license can fail grants nothing, and claims are read only
 * after the signature verifies.
 */
final class LicenseVerifierTest extends TestCase
{
    private function verify(string $document, string $now = '2026-06-01T00:00:00Z', ?array $keys = null, string $host = LicenseFixtures::HOST): LicenseVerification
    {
        return (new LicenseVerifier)->verify($document, $keys ?? LicenseFixtures::trustedKeys(), $host, CarbonImmutable::parse($now));
    }

    public function test_a_valid_license_grants_its_claims(): void
    {
        $result = $this->verify(LicenseFixtures::document());

        $this->assertSame(LicenseStatus::Valid, $result->status);
        $license = $result->granting();
        $this->assertNotNull($license);
        $this->assertSame(['lic-0001', 'Example Corp', LicenseFixtures::KEY_ID, LicenseFixtures::HOST, 'TEAM_READY', 50],
            [$license->id, $license->licensee, $license->keyId, $license->installationHost, $license->organizationPlan, $license->organizationSeats]);
        $this->assertSame('2027-01-01T00:00:00Z', $license->expiresAt->toIso8601ZuluString());
    }

    public function test_the_validity_period_is_enforced_at_its_exact_bounds(): void
    {
        $document = LicenseFixtures::document();
        $this->assertSame(LicenseStatus::NotYetValid, $this->verify($document, '2025-12-31T23:59:59Z')->status);
        $this->assertSame(LicenseStatus::Valid, $this->verify($document, '2026-01-01T00:00:00Z')->status);
        $this->assertSame(LicenseStatus::Valid, $this->verify($document, '2026-12-31T23:59:59Z')->status);
        $expired = $this->verify($document, '2027-01-01T00:00:00Z');
        $this->assertSame(LicenseStatus::Expired, $expired->status);
        // The verified license is reported (operators see what expired) but grants nothing.
        $this->assertSame('lic-0001', $expired->license?->id);
        $this->assertNull($expired->granting());
    }

    public function test_a_license_is_bound_to_its_installation_host(): void
    {
        $this->assertSame(LicenseStatus::WrongInstallation, $this->verify(LicenseFixtures::document(), host: 'other.example')->status);
        $this->assertSame(LicenseStatus::Valid, $this->verify(LicenseFixtures::document(), host: 'APP.CODEDNA.EXAMPLE')->status);
        $this->assertNull($this->verify(LicenseFixtures::document(), host: 'other.example')->granting());
    }

    public function test_an_altered_payload_or_signature_is_refused(): void
    {
        $document = json_decode(LicenseFixtures::document(), true);
        // Raise the seats in the signed payload without re-signing it.
        $claims = json_decode((string) base64_decode(strtr($document['payload'], '-_', '+/')), true);
        $claims['entitlements']['organization_seats'] = 100_000;
        $forged = [...$document, 'payload' => LicenseFixtures::base64url((string) json_encode($claims))];
        $this->assertSame(LicenseStatus::InvalidSignature, $this->verify((string) json_encode($forged))->status);

        // One flipped bit in the signature.
        $signature = (string) base64_decode(strtr($document['signature'], '-_', '+/'));
        $signature[10] = chr(ord($signature[10]) ^ 0x01);
        $this->assertSame(LicenseStatus::InvalidSignature, $this->verify((string) json_encode([...$document, 'signature' => LicenseFixtures::base64url($signature)]))->status);

        // Signed by another key under the trusted key id.
        $this->assertSame(LicenseStatus::InvalidSignature, $this->verify(LicenseFixtures::document([], [], "\x02"))->status);
    }

    public function test_only_trusted_keys_are_accepted(): void
    {
        // No trusted key at all: the shipped configuration.
        $this->assertSame(LicenseStatus::UnknownKey, $this->verify(LicenseFixtures::document(), keys: [])->status);
        $this->assertSame(LicenseStatus::UnknownKey, $this->verify(LicenseFixtures::document([], ['key_id' => 'other-key']))->status);
        // A configured key that is not a 32-byte public key is never used.
        $this->assertSame(LicenseStatus::UnknownKey, $this->verify(LicenseFixtures::document(), keys: [LicenseFixtures::KEY_ID => base64_encode('short')])->status);
    }

    public function test_unsupported_versions_are_refused(): void
    {
        $this->assertSame(LicenseStatus::UnsupportedVersion, $this->verify(LicenseFixtures::document([], ['version' => 2]))->status);
        $this->assertSame(LicenseStatus::UnsupportedVersion, $this->verify(LicenseFixtures::document(['schema' => 'codedna.license.v2']))->status);
    }

    public function test_an_empty_document_is_no_license(): void
    {
        $this->assertSame(LicenseStatus::Absent, $this->verify('')->status);
        $this->assertSame(LicenseStatus::Absent, $this->verify(" \n")->status);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEnvelopes(): iterable
    {
        $valid = json_decode(LicenseFixtures::document(), true);
        yield 'not JSON' => ['not a license'];
        yield 'a list' => ['[1,2]'];
        yield 'missing signature' => [(string) json_encode(array_diff_key($valid, ['signature' => 1]))];
        yield 'unknown field' => [(string) json_encode([...$valid, 'extra' => true])];
        yield 'other format' => [(string) json_encode([...$valid, 'format' => 'something-else'])];
        yield 'bad key id' => [(string) json_encode([...$valid, 'key_id' => '../etc'])];
        yield 'payload not base64url' => [(string) json_encode([...$valid, 'payload' => 'a+b/c='])];
        yield 'short signature' => [(string) json_encode([...$valid, 'signature' => LicenseFixtures::base64url('short')])];
        yield 'deep nesting' => [str_repeat('[', 50).str_repeat(']', 50)];
    }

    #[DataProvider('malformedEnvelopes')]
    public function test_malformed_envelopes_are_refused(string $document): void
    {
        $this->assertSame(LicenseStatus::Malformed, $this->verify($document)->status);
    }

    /** @return iterable<string, array{array<string, mixed>|string}> */
    public static function invalidClaims(): iterable
    {
        yield 'signed non-JSON' => ['signed garbage'];
        yield 'unknown claim' => [LicenseFixtures::claims() + ['admin' => true]];
        yield 'missing claim' => [(string) json_encode(array_diff_key(LicenseFixtures::claims(), ['licensee' => 1]))];
        yield 'unknown entitlement' => [LicenseFixtures::claims(['entitlements' => ['organization_plan' => 'TEAM_READY', 'organization_seats' => 5, 'personal_plan' => 'PRO']])];
        yield 'personal plan' => [LicenseFixtures::claims(['entitlements' => ['organization_plan' => 'PRO', 'organization_seats' => 5]])];
        yield 'zero seats' => [LicenseFixtures::claims(['entitlements' => ['organization_plan' => 'TEAM_READY', 'organization_seats' => 0]])];
        yield 'too many seats' => [LicenseFixtures::claims(['entitlements' => ['organization_plan' => 'TEAM_READY', 'organization_seats' => 100_001]])];
        yield 'seats as string' => [LicenseFixtures::claims(['entitlements' => ['organization_plan' => 'TEAM_READY', 'organization_seats' => '50']])];
        yield 'impossible date' => [LicenseFixtures::claims(['expires_at' => '2027-02-30T00:00:00Z'])];
        yield 'local time' => [LicenseFixtures::claims(['expires_at' => '2027-01-01T00:00:00+02:00'])];
        yield 'ends before it starts' => [LicenseFixtures::claims(['not_before' => '2027-01-01T00:00:00Z', 'expires_at' => '2026-01-01T00:00:00Z'])];
        yield 'host with path' => [LicenseFixtures::claims(['installation' => ['app_url_host' => 'example.com/path']])];
        yield 'empty licensee' => [LicenseFixtures::claims(['licensee' => ''])];
        yield 'control characters' => [LicenseFixtures::claims(['licensee' => "Example\u{0007}Corp"])];
    }

    /** @param  array<string, mixed>|string  $claims */
    #[DataProvider('invalidClaims')]
    public function test_signed_but_invalid_claims_are_refused(array|string $claims): void
    {
        $result = $this->verify(LicenseFixtures::document($claims));

        $this->assertSame(LicenseStatus::Malformed, $result->status);
        $this->assertNull($result->license);
    }

    public function test_claim_order_does_not_matter(): void
    {
        $this->assertSame(LicenseStatus::Valid, $this->verify(LicenseFixtures::document(array_reverse(LicenseFixtures::claims(), true)))->status);
    }
}
