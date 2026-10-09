<?php

declare(strict_types=1);

namespace App\Http\Requests\Repositories;

use App\Http\Requests\GitHub\OnlyFields;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/repository-providers/{provider}/authorizations: an optional project to return to. */
final class StartProviderAuthorizationRequest extends FormRequest
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
