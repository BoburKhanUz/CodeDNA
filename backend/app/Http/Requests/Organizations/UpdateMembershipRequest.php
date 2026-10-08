<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Http\Requests\Concerns\AuthorizesOrganizationView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/organizations/{organization}/members/{membership}: a new
 * role (ADMIN or MEMBER) and/or status (ACTIVE or SUSPENDED). OWNER is
 * never assignable; removal is DELETE. Who may do it is decided by
 * ChangeMembership, never by this input.
 */
final class UpdateMembershipRequest extends FormRequest
{
    use AuthorizesOrganizationView;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required_without:status', 'string', Rule::in(array_map(fn (OrganizationRole $r): string => $r->value, OrganizationRole::assignable()))],
            'status' => ['required_without:role', 'string', Rule::in([MembershipStatus::Active->value, MembershipStatus::Suspended->value])],
        ];
    }

    public function role(): ?OrganizationRole
    {
        return $this->filled('role') ? OrganizationRole::from($this->string('role')->toString()) : null;
    }

    public function status(): ?MembershipStatus
    {
        return $this->filled('status') ? MembershipStatus::from($this->string('status')->toString()) : null;
    }
}
