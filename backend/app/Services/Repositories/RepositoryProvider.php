<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;

/**
 * An OAuth repository provider adapter (Phase 28,
 * docs/integrations/provider-architecture.md#the-adapter-contract).
 * Provider API models stay behind this boundary: every method returns
 * validated, normalized values, and every failure is a ProviderException.
 * Adapters never accept a URL from a caller; they build every request from
 * their configured origins and validated identifiers.
 */
interface RepositoryProvider
{
    public function key(): RepositoryProviderKey;

    public function settings(): ProviderSettings;

    /** Where the browser goes to authorize CodeDNA, carrying the single-use state. */
    public function authorizationUrl(string $state): string;

    /** @throws ProviderException */
    public function exchangeCode(#[\SensitiveParameter] string $code): ProviderTokens;

    /** @throws ProviderException */
    public function refresh(#[\SensitiveParameter] string $refreshToken): ProviderTokens;

    /**
     * Revokes the authorization at the provider.
     *
     * @return bool false when the provider has no revocation endpoint
     *
     * @throws ProviderException
     */
    public function revoke(#[\SensitiveParameter] string $accessToken): bool;

    /** @throws ProviderException */
    public function identity(#[\SensitiveParameter] string $token): ProviderIdentity;

    /** Whether $id has this provider's repository ID syntax (checked before any request). */
    public function isRepositoryId(string $id): bool;

    /**
     * One page of the repositories the user can read.
     *
     * @return array{repositories: list<ProviderRepository>, has_more: bool}
     *
     * @throws ProviderException
     */
    public function repositories(#[\SensitiveParameter] string $token, int $page, int $perPage): array;

    /** @throws ProviderException NotFound when the user cannot read it */
    public function repository(#[\SensitiveParameter] string $token, string $id): ProviderRepository;

    /**
     * @return array{branches: list<string>, has_more: bool}
     *
     * @throws ProviderException
     */
    public function branches(#[\SensitiveParameter] string $token, ProviderRepository $repository, int $page, int $perPage): array;

    /** The branch's current commit (40 lowercase hex). @throws ProviderException */
    public function branchHead(#[\SensitiveParameter] string $token, ProviderRepository $repository, string $branch): string;

    /** Streams the archive of exactly $sha into $destination (at most $maxBytes). @throws ProviderException */
    public function downloadArchive(#[\SensitiveParameter] string $token, ProviderRepository $repository, string $sha, string $destination, int $maxBytes): void;
}
