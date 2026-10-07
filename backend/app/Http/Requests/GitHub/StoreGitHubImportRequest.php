<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/projects/{project}/github/imports: no fields. Repository and
 * branch come from the connection, the commit from GitHub.
 */
final class StoreGitHubImportRequest extends FormRequest
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
        return [];
    }

    protected function allowedFields(): array
    {
        return [];
    }
}
