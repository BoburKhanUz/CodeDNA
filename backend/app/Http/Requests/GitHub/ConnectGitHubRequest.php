<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/projects/{project}/github: a repository ID and optionally a
 * branch. Everything else about the repository is read from GitHub.
 */
final class ConnectGitHubRequest extends FormRequest
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
            'repository_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['repository_id', 'branch'];
    }
}
