<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\Organizations\InvitationStatus;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;

/**
 * What the holder of an invitation link may see before signing in
 * (docs/teams/invitations.md#preview): its status and, only while it is
 * open, the organization's name, the role and expiry, and a masked email
 * so the visitor knows which account to use. Nothing else about the
 * organization, and nothing at all for an unknown token (404).
 *
 * @phpstan-type Preview array{status: string, organization: array{name: string}|null, role: string|null, expires_at: string|null, email_hint: string|null}
 */
final readonly class PreviewInvitation
{
    /** @return Preview */
    public function handle(string $token): array
    {
        if (! InvitationToken::wellFormed($token)) {
            throw new ApiException(ErrorCode::ResourceNotFound);
        }
        $invitation = OrganizationInvitation::query()->with('organization')->where('token_hash', InvitationToken::hash($token))->first()
            ?? throw new ApiException(ErrorCode::ResourceNotFound);
        $status = $invitation->statusAt(Carbon::now());
        $open = $status === InvitationStatus::Pending;

        return [
            'status' => $status->value,
            'organization' => $open ? ['name' => $invitation->organization->name] : null,
            'role' => $open ? $invitation->role->value : null,
            'expires_at' => $open ? $invitation->expires_at->toIso8601ZuluString() : null,
            'email_hint' => $open ? self::mask($invitation->email) : null,
        ];
    }

    /** "jane.doe@example.com" -> "j…@example.com". */
    private static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'…@'.$domain;
    }
}
