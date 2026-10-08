<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/organizations: only a name. The creator becomes OWNER; the
 * slug is generated; status, owner and plan are never taken from input.
 */
final class StoreOrganizationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['name' => ['required', ...OrganizationRules::name()]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return OrganizationRules::messages();
    }
}
