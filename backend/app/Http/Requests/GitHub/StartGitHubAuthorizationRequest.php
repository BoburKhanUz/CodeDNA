<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/github/authorizations: optionally the project to return to
 * (its ownership is checked by the controller, 404 otherwise).
 */
final class StartGitHubAuthorizationRequest extends FormRequest
{
    use OnlyFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['project_id' => ['sometimes', 'nullable', 'string', 'ulid']];
    }

    protected function allowedFields(): array
    {
        return ['project_id'];
    }
}
