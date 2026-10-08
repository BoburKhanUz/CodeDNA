<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationBillingAccount;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Billing\Catalog\TeamEntitlementsV1;
use App\Services\Billing\PlanCatalog;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates an organization with its creator as OWNER (docs/teams/teams-architecture.md#creation).
 *
 * One transaction: the organization, the OWNER membership, the billing
 * account (team entitlements 1.0.0: the FREE plan and its seat entitlement)
 * and the ORGANIZATION_CREATED audit event all exist, or none does. The
 * database checks the owner invariant when the transaction commits.
 *
 * The slug is generated, never chosen: a readable prefix from the name and
 * a random suffix. It is unique without the API ever telling a caller that
 * another organization's slug exists.
 */
final readonly class CreateOrganization
{
    public function __construct(private PlanCatalog $catalog, private OrganizationAudit $audit) {}

    public function handle(User $creator, string $name): Organization
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $organization = DB::transaction(fn (): Organization => $this->create($creator, $name));
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
        Log::info('organization.created', ['organization_id' => $organization->id, 'user_id' => $creator->id]);

        return $organization;
    }

    private function create(User $creator, string $name): Organization
    {
        $now = Carbon::now();
        $organization = new Organization;
        $organization->forceFill([
            'owner_user_id' => $creator->getKey(),
            'name' => $name,
            'slug' => self::slug($name),
            'status' => OrganizationStatus::Active,
        ])->save();

        $membership = new OrganizationMembership;
        $membership->forceFill([
            'organization_id' => $organization->id,
            'user_id' => $creator->getKey(),
            'role' => OrganizationRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => $now,
        ])->save();

        $account = new OrganizationBillingAccount;
        $account->forceFill([
            'organization_id' => $organization->id,
            'billing_plan_id' => $this->catalog->free()->id,
            'entitlement_version' => TeamEntitlementsV1::VERSION,
            'seat_limit' => TeamEntitlementsV1::SEATS,
        ])->save();

        $this->audit->record($organization, $creator, OrganizationAuditAction::OrganizationCreated, $organization, ['name' => $name]);

        return $organization;
    }

    private static function slug(string $name): string
    {
        $prefix = Str::limit(Str::slug($name), 40, '');
        $prefix = trim($prefix, '-');

        return ($prefix === '' ? 'team' : $prefix).'-'.Str::lower(Str::random(6));
    }
}
