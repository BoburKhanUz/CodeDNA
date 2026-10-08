<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\AuthorizesOrganizationView;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/organizations/{organization}: the name only. The slug,
 * owner and status cannot be set here (archiving has its own route).
 */
final class UpdateOrganizationRequest extends FormRequest
{
    use AuthorizesOrganizationView;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', ...OrganizationRules::name()],
            'slug' => ['prohibited'],
            'status' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...OrganizationRules::messages(),
            'status.prohibited' => 'Use POST /api/v1/organizations/{organization}/archive to archive an organization.',
            'owner_user_id.prohibited' => 'The owner of an organization cannot be changed.',
        ];
    }
}
