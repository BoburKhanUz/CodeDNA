<?php

declare(strict_types=1);

namespace App\Http\Requests\Repositories;

use App\Http\Requests\GitHub\OnlyFields;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** PATCH /api/v1/projects/{project}/repository-provider: the branch only. */
final class UpdateProviderRequest extends FormRequest
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
        return ['branch' => ['required', 'string', 'max:255']];
    }

    protected function allowedFields(): array
    {
        return ['branch'];
    }
}
