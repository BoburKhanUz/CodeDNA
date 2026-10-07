<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes a read of the route's project before the request is validated
 * (Phase 21). Laravel runs authorize() first, so another user's project
 * answers 404 whatever the query string holds; otherwise invalid input
 * would answer 422 for an existing project and 404 for a missing one,
 * confirming that the ID exists. Routes without a {project} are unaffected.
 */
trait AuthorizesProjectView
{
    public function authorize(): Response|bool
    {
        $project = $this->route('project');

        return $project instanceof Project ? Gate::inspect('view', $project) : true;
    }
}
