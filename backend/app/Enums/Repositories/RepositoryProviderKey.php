<?php

declare(strict_types=1);

namespace App\Enums\Repositories;

/**
 * OAuth repository providers (Phase 28, docs/integrations/provider-architecture.md).
 * GitHub is not listed: it is a GitHub App with its own adapter
 * (App\Services\GitHub) and shares only the import core and quota.
 */
enum RepositoryProviderKey: string
{
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    public function label(): string
    {
        return match ($this) {
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket Cloud',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }
}
