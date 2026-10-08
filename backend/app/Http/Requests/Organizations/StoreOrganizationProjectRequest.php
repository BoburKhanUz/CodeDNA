<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\AuthorizesOrganizationView;
use App\Http\Requests\Projects\ProjectRules;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\Organization;

/**
 * POST /api/v1/organizations/{organization}/projects: the same fields as a
 * personal project; the slug is unique within the organization. Who may
 * create is decided by CreateProject (ADMIN or OWNER, ACTIVE organization).
 */
final class StoreOrganizationProjectRequest extends StoreProjectRequest
{
    use AuthorizesOrganizationView;

    protected function organizationId(): ?string
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? (string) $organization->getKey() : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...ProjectRules::messages(), 'slug.unique' => 'The organization already has a project with this slug.'];
    }
}
