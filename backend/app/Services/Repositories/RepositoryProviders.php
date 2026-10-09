<?php

declare(strict_types=1);

namespace App\Services\Repositories;

use App\Enums\Repositories\RepositoryProviderKey;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as Http;

/**
 * The configured repository providers (Phase 28): the only way an adapter is
 * obtained. A provider without client credentials is reported as not
 * configured and never contacted.
 */
final readonly class RepositoryProviders
{
    public function __construct(private Repository $config, private Http $http) {}

    public function settings(RepositoryProviderKey $key): ProviderSettings
    {
        return ProviderSettings::fromConfig($this->config, $key);
    }

    /**
     * @throws ApiException PROVIDER_NOT_CONFIGURED
     */
    public function get(RepositoryProviderKey $key): RepositoryProvider
    {
        $settings = $this->settings($key);
        if (! $settings->configured()) {
            throw new ApiException(ErrorCode::ProviderNotConfigured, null, ['provider' => $key->value]);
        }
        $http = new ProviderHttp($this->http, $settings);

        return match ($key) {
            RepositoryProviderKey::GitLab => new GitLabProvider($http, $settings),
            RepositoryProviderKey::Bitbucket => new BitbucketProvider($http, $settings),
        };
    }
}
