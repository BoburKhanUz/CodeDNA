<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\Billing\Feature;
use App\Enums\Billing\QuotaKey;
use App\Enums\GitHub\GitHubImportStatus;
use App\Enums\Repositories\ProviderImportFailure;
use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Jobs\ImportProviderSource;
use App\Models\Project;
use App\Models\RepositoryProviderConnection;
use App\Models\RepositoryProviderImport;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\UsageService;
use App\Services\Repositories\ProviderUserAccess;
use App\Services\Repositories\RepositoryProviders;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Queues an import of the connected GitLab or Bitbucket branch (Phase 28,
 * docs/integrations/provider-architecture.md#import-pipeline).
 *
 * The request carries nothing: provider, repository and branch come from the
 * stored connection; the commit is resolved by the job. Before queueing, the
 * provider confirms — with the requesting user's own token — that they can
 * still read the repository; the job then uses that same user's
 * authorization. At most one import per project is in progress (unique
 * index); asking again returns it. One unit of the plan's repository import
 * quota (GITHUB_IMPORTS, shared by every provider) is consumed under the
 * project lock and refunded when the import ends FAILED.
 */
final readonly class RequestProviderImport
{
    public function __construct(
        private RepositoryProviders $providers,
        private ProviderUserAccess $access,
        private ConnectionInterface $db,
        private Repository $config,
        private Entitlements $entitlements,
        private UsageService $usage,
    ) {}

    public function handle(Project $project, User $actor): RequestedProviderImport
    {
        if (! $project->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        $this->entitlements->require($project, Feature::GitHubIntegration);
        $connection = ProjectSource::requireProviderConnection($project);
        $provider = $this->providers->get($connection->provider);
        $token = $this->access->token($provider, (string) $actor->getKey());
        ConnectProviderRepository::verifiedRepository($provider, $token, $connection->repository_id);
        unset($token);

        try {
            $result = $this->db->transaction(fn (): RequestedProviderImport => $this->queue($project, $connection, $actor));
        } catch (UniqueConstraintViolationException) {
            $active = RepositoryProviderImport::query()->where('project_id', $project->id)
                ->whereIn('status', GitHubImportStatus::activeValues())->first()
                ?? throw new ApiException(ErrorCode::InternalError);

            return new RequestedProviderImport($active, false);
        }

        if ($result->created) {
            ImportProviderSource::dispatch($result->import->id)->afterCommit();
            Log::info('repository_provider.import_queued', [
                'provider' => $connection->provider->value,
                'project_id' => $project->id,
                'import_id' => $result->import->id,
                'connection_id' => $connection->id,
                'user_id' => $actor->getKey(),
            ]);
        }

        return $result;
    }

    private function queue(Project $project, RepositoryProviderConnection $connection, User $actor): RequestedProviderImport
    {
        $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive()) {
            throw new ApiException(ErrorCode::ProjectArchived);
        }
        $current = RepositoryProviderConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
        if (! $current->isActive()) {
            throw new ApiException(ErrorCode::ProviderNotConnected);
        }

        $now = Carbon::now();
        $active = RepositoryProviderImport::query()->where('project_id', $locked->id)
            ->whereIn('status', GitHubImportStatus::activeValues())->lockForUpdate()->first();
        if ($active !== null) {
            $staleAfter = (int) $this->config->get('codedna.github.import_stale_after_seconds');
            if ($active->updated_at !== null && $active->updated_at->gt($now->copy()->subSeconds($staleAfter))) {
                return new RequestedProviderImport($active, false);
            }
            $active->forceFill(['status' => GitHubImportStatus::Failed, 'failure_code' => ProviderImportFailure::ImportFailed->value, 'completed_at' => $now])->save();
            Log::warning('repository_provider.import_stale', ['project_id' => $locked->id, 'import_id' => $active->id]);
        }

        $import = new RepositoryProviderImport;
        $import->forceFill([
            'connection_id' => $current->id,
            'project_id' => $current->project_id,
            'provider' => $current->provider,
            'requested_by' => $actor->getKey(),
            'repository_id' => $current->repository_id,
            'repository_full_name' => $current->repository_full_name,
            'ref' => $current->branch,
            'status' => GitHubImportStatus::Queued,
        ])->save();
        $this->usage->consume($locked, QuotaKey::GitHubImports, 'repository_import', $import->id);

        return new RequestedProviderImport($import, true);
    }
}
