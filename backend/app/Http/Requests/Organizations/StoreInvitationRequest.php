<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\Organizations\OrganizationRole;
use App\Http\Requests\Concerns\AuthorizesOrganizationView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/organizations/{organization}/invitations: an email and a
 * role (ADMIN or MEMBER). The email is compared in canonical form
 * (trimmed, lowercase).
 */
final class StoreInvitationRequest extends FormRequest
{
    use AuthorizesOrganizationView;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'role' => ['required', 'string', Rule::in(array_map(fn (OrganizationRole $r): string => $r->value, OrganizationRole::assignable()))],
        ];
    }

    public function role(): OrganizationRole
    {
        return OrganizationRole::from($this->string('role')->toString());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
