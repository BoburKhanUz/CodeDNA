<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/github/callback: what GitHub put in the browser's callback
 * URL. Only the state and the code are used; installation_id and
 * setup_action are accepted and ignored (installations are read from
 * GitHub as the user).
 */
final class CompleteGitHubAuthorizationRequest extends FormRequest
{
    use OnlyFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,255}$/'],
            'installation_id' => ['sometimes', 'nullable'],
            'setup_action' => ['sometimes', 'nullable'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['state', 'code', 'installation_id', 'setup_action'];
    }
}
