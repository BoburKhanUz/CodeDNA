<?php

declare(strict_types=1);

namespace App\Http\Requests\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Http\Requests\GitHub\OnlyFields;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/projects/{project}/repository-provider: a provider, a
 * repository ID from that provider's listing and optionally a branch.
 * Everything else about the repository is read from the provider.
 */
final class ConnectProviderRequest extends FormRequest
{
    use OnlyFields;

    public function authorize(): Response
    {
        return Gate::inspect('connectSource', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::enum(RepositoryProviderKey::class)],
            'repository_id' => ['required', 'string', 'max:80', 'regex:/^[0-9A-Za-z{}\/-]+$/'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['provider', 'repository_id', 'branch'];
    }
}
