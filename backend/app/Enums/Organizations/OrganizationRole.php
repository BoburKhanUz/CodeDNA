<?php

declare(strict_types=1);

namespace App\Enums\Organizations;

/**
 * Membership roles, ordered OWNER > ADMIN > MEMBER (docs/teams/authorization.md).
 */
enum OrganizationRole: string
{
    case Owner = 'OWNER';
    case Admin = 'ADMIN';
    case Member = 'MEMBER';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    /** Roles that can be given by invitation or role change: never OWNER. */
    public static function assignable(): array
    {
        return [self::Admin, self::Member];
    }
}
