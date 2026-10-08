<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Organizations\ArchiveOrganization;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\UpdateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ListOrganizationResourcesRequest;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Http\Resources\Organizations\OrganizationResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\QuotaService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/organizations (Phase 24, docs/teams/teams-architecture.md):
 * the caller's organizations. Authorization is OrganizationPolicy /
 * OrganizationAccess; every change runs in an action.
 */
final class OrganizationController extends Controller
{
    public function index(ListOrganizationResourcesRequest $request): PaginatedCollection
    {
        /** @var User $user */
        $user = $request->user();
        $organizations = OrganizationQueries::forMember((string) $user->getKey())
            ->orderBy('organizations.name')->orderBy('organizations.id')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($organizations, OrganizationResource::class);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $create): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $create->handle($user, $request->string('name')->toString());

        return (new OrganizationResource($this->reload($organization, $user)))->response()->setStatusCode(201);
    }

    public function show(Request $request, Organization $organization, Gate $gate): OrganizationResource
    {
        $gate->authorize('view', $organization);
        /** @var User $user */
        $user = $request->user();

        return new OrganizationResource($this->reload($organization, $user));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganization $update): OrganizationResource
    {
        /** @var User $user */
        $user = $request->user();
        $update->handle($organization, $user, $request->string('name')->toString());

        return new OrganizationResource($this->reload($organization, $user));
    }

    /** POST .../archive: OWNER only, irreversible; nothing is deleted. */
    public function archive(Request $request, Organization $organization, Gate $gate, ArchiveOrganization $archive): OrganizationResource
    {
        $gate->authorize('view', $organization);
        /** @var User $user */
        $user = $request->user();
        $archive->handle($organization, $user);

        return new OrganizationResource($this->reload($organization, $user));
    }

    /**
     * GET .../billing: the organization as a billing subject (ADMIN or
     * OWNER). Read-only: there are no team payments yet.
     */
    public function billing(Organization $organization, Gate $gate, BillingContextResolver $contexts, QuotaService $quotas): JsonResponse
    {
        $gate->authorize('administer', $organization);
        $context = $contexts->resolveOrganization((string) $organization->getKey());
        $account = $organization->billingAccount()->firstOrFail();

        return new JsonResponse(['data' => [
            'type' => 'organization_billing',
            'plan' => ['key' => $context->plan->key, 'version' => $context->plan->version, 'name' => $context->plan->name],
            'entitlement_version' => $account->entitlement_version,
            'seats' => $quotas->seats($organization, $account),
            'period' => ['start' => $context->periodStart->toIso8601ZuluString(), 'end' => $context->periodEnd->toIso8601ZuluString()],
            'quotas' => array_map(fn (array $q): array => [
                'key' => $q['key']->value, 'label' => $q['key']->label(), 'unit' => $q['key']->unit(), 'period' => $q['key']->period()->value,
                'limit' => $q['limit'], 'used' => $q['used'], 'remaining' => $q['remaining'], 'unlimited' => $q['limit'] === null,
                'resets_at' => $q['resets_at'],
            ], $quotas->summary($context)),
        ]]);
    }

    private function reload(Organization $organization, User $user): Organization
    {
        return OrganizationQueries::forMember((string) $user->getKey())->whereKey($organization->getKey())->firstOrFail();
    }
}
