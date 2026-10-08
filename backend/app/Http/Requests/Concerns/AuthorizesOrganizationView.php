<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Organization;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Checks membership in the route's organization before the request is
 * validated (Phase 24, as AuthorizesProjectView): a non-member gets 404
 * whatever the input holds, so validation errors never confirm that an
 * organization exists. The finer checks (role, organization state, target)
 * are made by the actions.
 */
trait AuthorizesOrganizationView
{
    public function authorize(): Response|bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? Gate::inspect('view', $organization) : true;
    }
}
