<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\QuotaService;
use App\Services\Enterprise\EnterpriseEdition;
use App\Services\Enterprise\LicenseStatus;
use App\Support\ConfigurationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * The enterprise edition (Phase 27, docs/enterprise/licensing.md): a valid
 * license puts organizations on its plan and seat limit; anything else
 * leaves the Community behavior exactly as it was, and personal billing is
 * never touched.
 */
final class EnterpriseEditionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15T12:00:00Z'));
        $this->owner = User::factory()->create();
        $this->organization = OrganizationFixtures::create($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function organizationBilling(): array
    {
        app()->forgetScopedInstances();

        return $this->asUser($this->owner)->getJson("/api/v1/organizations/{$this->organization->id}/billing")->assertOk()->json('data');
    }

    /** @return array<string, int|null> */
    private function quota(array $billing, string $key): array
    {
        $quota = collect($billing['quotas'])->firstWhere('key', $key);

        return ['limit' => $quota['limit'], 'used' => $quota['used']];
    }

    public function test_without_a_license_organizations_keep_their_billing_account(): void
    {
        $billing = $this->organizationBilling();

        $this->assertSame(['COMMUNITY', 'BILLING_ACCOUNT', 'FREE'], [$billing['edition'], $billing['plan_source'], $billing['plan']['key']]);
        $this->assertSame(5, $billing['seats']['limit']);
        $this->assertSame(3, $this->quota($billing, 'ACTIVE_PROJECTS')['limit']);
        $this->asUser($this->owner)->getJson('/api/v1/installation')->assertOk()
            ->assertJsonPath('data.edition', 'COMMUNITY')->assertJsonPath('data.license.status', 'ABSENT')
            ->assertJsonPath('data.license.organization_seats', null);
    }

    public function test_a_valid_license_puts_organizations_on_its_plan_and_seats(): void
    {
        LicenseFixtures::install(LicenseFixtures::document());

        $billing = $this->organizationBilling();

        $this->assertSame(['ENTERPRISE', 'ENTERPRISE_LICENSE', 'TEAM_READY'], [$billing['edition'], $billing['plan_source'], $billing['plan']['key']]);
        $this->assertSame(['limit' => 50, 'used' => 1, 'remaining' => 49], $billing['seats']);
        $this->assertSame(25, $this->quota($billing, 'ACTIVE_PROJECTS')['limit']);
        // Nothing is stored: the billing account itself is unchanged.
        $this->assertSame(5, (int) DB::table('organization_billing_accounts')->where('organization_id', $this->organization->id)->value('seat_limit'));
    }

    public function test_a_license_never_changes_personal_billing(): void
    {
        LicenseFixtures::install(LicenseFixtures::document());

        $billing = $this->asUser($this->owner)->getJson('/api/v1/billing')->assertOk()->json('data');

        $this->assertSame('FREE', $billing['plan']['key']);
        $this->assertNotContains('AI_ASSESSMENT', array_column(array_filter($billing['entitlements'], fn (array $e): bool => $e['included']), 'feature'));
    }

    public function test_a_license_never_lowers_a_higher_seat_limit(): void
    {
        DB::table('organization_billing_accounts')->where('organization_id', $this->organization->id)->update(['seat_limit' => 80]);
        LicenseFixtures::install(LicenseFixtures::document());

        $this->assertSame(80, $this->organizationBilling()['seats']['limit']);
    }

    /** @return iterable<string, array{\Closure(): string}> */
    public static function nonGrantingLicenses(): iterable
    {
        yield 'expired' => [fn (): string => LicenseFixtures::document(['expires_at' => '2026-06-01T00:00:00Z'])];
        yield 'not yet valid' => [fn (): string => LicenseFixtures::document(['not_before' => '2026-07-01T00:00:00Z'])];
        yield 'forged signature' => [fn (): string => LicenseFixtures::document([], [], "\x09")];
        yield 'other installation' => [fn (): string => LicenseFixtures::document(['installation' => ['app_url_host' => 'other.example']])];
        yield 'garbage' => [fn (): string => 'not a license'];
    }

    /** @param  \Closure(): string  $document */
    #[DataProvider('nonGrantingLicenses')]
    public function test_a_license_that_does_not_verify_grants_nothing(\Closure $document): void
    {
        LicenseFixtures::install($document());

        $billing = $this->organizationBilling();
        $this->assertSame(['COMMUNITY', 'BILLING_ACCOUNT', 'FREE', 5], [$billing['edition'], $billing['plan_source'], $billing['plan']['key'], $billing['seats']['limit']]);
        $installation = $this->asUser($this->owner)->getJson('/api/v1/installation')->assertOk()->json('data');
        $this->assertSame('COMMUNITY', $installation['edition']);
        $this->assertNotSame('VALID', $installation['license']['status']);
        $this->assertNull($installation['license']['organization_plan']);
    }

    public function test_seats_are_enforced_against_the_license_and_fall_back_when_it_expires(): void
    {
        for ($i = 0; $i < 4; $i++) {
            OrganizationFixtures::member($this->organization);
        }
        $requireSeat = fn () => DB::transaction(fn () => app(QuotaService::class)->requireSeat($this->organization));

        // Community: 5 of 5 seats used, the next activation is refused.
        try {
            $requireSeat();
            $this->fail('A sixth seat was granted without a license.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::SeatLimitReached, $e->errorCode);
        }

        LicenseFixtures::install(LicenseFixtures::document());
        $requireSeat();

        // The license expires: back to the account's 5 seats, nothing kept.
        Carbon::setTestNow(Carbon::parse('2027-01-01T00:00:00Z'));
        app()->forgetScopedInstances();
        $this->expectException(ApiException::class);
        $requireSeat();
    }

    public function test_a_license_grants_no_access_across_organizations(): void
    {
        LicenseFixtures::install(LicenseFixtures::document());
        $outsider = User::factory()->create();
        $member = OrganizationFixtures::member($this->organization)->user;

        $this->asUser($outsider)->getJson("/api/v1/organizations/{$this->organization->id}/billing")->assertNotFound();
        $this->asUser($member)->getJson("/api/v1/organizations/{$this->organization->id}/billing")->assertForbidden();
        $project = OrganizationFixtures::project($this->organization, $this->owner);
        $this->asUser($outsider)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    }

    public function test_the_installation_status_needs_a_session_and_never_exposes_license_material(): void
    {
        $path = LicenseFixtures::install($document = LicenseFixtures::document());
        $this->getJson('/api/v1/installation')->assertUnauthorized();

        $response = $this->asUser($this->owner)->getJson('/api/v1/installation')->assertOk();

        $response->assertJsonPath('data.edition', 'ENTERPRISE')->assertJsonPath('data.license.status', 'VALID')
            ->assertJsonPath('data.license.licensee', 'Example Corp')->assertJsonPath('data.license.expires_at', '2027-01-01T00:00:00Z')
            ->assertJsonPath('data.license.organization_seats', 50)->assertJsonPath('data.registration.mode', 'open');
        $body = (string) $response->getContent();
        $envelope = json_decode($document, true);
        foreach ([$envelope['signature'], $envelope['payload'], $path, LicenseFixtures::KEY_ID, LicenseFixtures::HOST, 'lic-0001'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_a_renewed_license_applies_without_a_restart(): void
    {
        $path = LicenseFixtures::install(LicenseFixtures::document(['expires_at' => '2026-06-01T00:00:00Z']));
        $this->assertSame(LicenseStatus::Expired, app(EnterpriseEdition::class)->verification()->status);

        file_put_contents($path, LicenseFixtures::document(['license_id' => 'lic-0002', 'expires_at' => '2027-06-01T00:00:00Z']));
        app()->forgetScopedInstances();

        $this->assertSame('lic-0002', app(EnterpriseEdition::class)->verification()->granting()?->id);
    }

    public function test_a_configured_license_file_that_cannot_be_read_is_a_configuration_error_and_grants_nothing(): void
    {
        config(['codedna.enterprise.license_path' => '/nonexistent/license', 'license.trusted_keys' => LicenseFixtures::trustedKeys()]);
        app()->forgetScopedInstances();

        $this->assertSame(LicenseStatus::Unreadable, app(EnterpriseEdition::class)->verification()->status);
        $this->assertContains('CODEDNA_LICENSE_PATH does not name a readable file.', (new ConfigurationValidator)->problems(config(), 'testing'));

        // Oversized files are refused unread.
        $path = LicenseFixtures::install(str_repeat(' ', 20_000).LicenseFixtures::document());
        $this->assertSame(LicenseStatus::Unreadable, app(EnterpriseEdition::class)->verification()->status);
        $this->assertFileExists($path);
    }

    public function test_the_license_command_reports_status_and_fails_on_a_license_that_does_not_grant(): void
    {
        $this->artisan('codedna:license')->expectsOutput('Edition: Community')->expectsOutput('License: ABSENT')->assertSuccessful();

        LicenseFixtures::install($valid = LicenseFixtures::document());
        $this->artisan('codedna:license')->expectsOutput('Edition: Enterprise')->expectsOutput('License: VALID')
            ->doesntExpectOutputToContain(json_decode($valid, true)['signature'])->assertSuccessful();

        LicenseFixtures::install(LicenseFixtures::document(['expires_at' => '2026-06-01T00:00:00Z']));
        $this->artisan('codedna:license')->expectsOutput('License: EXPIRED')->assertFailed();
    }
}
