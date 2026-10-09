<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\AnalysisRunStatus;
use App\Enums\Assessment\AssessmentStatus;
use App\Enums\Billing\QuotaKey;
use App\Enums\Challenge\SubmissionStatus;
use App\Enums\GitHub\GitHubImportStatus;
use App\Exceptions\InvalidConfigurationException;
use App\Models\AiAssessment;
use App\Models\AiInsight;
use App\Models\AnalysisRun;
use App\Models\ChallengeSubmission;
use App\Models\GitHubImport;
use App\Models\RepositoryProviderImport;
use App\Policies\ViewDecisions;
use App\Services\Ai\AiGateway;
use App\Services\Ai\FakeModelClient;
use App\Services\Ai\GatewayAiProvider;
use App\Services\Ai\HttpModelTransport;
use App\Services\Ai\ModelClient;
use App\Services\Ai\OllamaClient;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\FakeAiProvider;
use App\Services\Assessment\Provider\OpenAiCompatibleProvider;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\Provider\PaymentProviders;
use App\Services\Billing\UsageService;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\SpoolChallengeEvaluator;
use App\Services\Challenge\Evaluator\UnavailableChallengeEvaluator;
use App\Services\Enterprise\EnterpriseEdition;
use App\Services\GitHub\GitHubSettings;
use App\Services\Growth\GrowthRules;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapRules;
use App\Support\ConfigurationValidator;
use App\Support\Session\RedisSessionHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RedisStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The AI provider behind the assessment pipeline (Phase 15), chosen
        // only by configuration (docs/architecture/ai-assessment-v1.md#providers).
        // Phase 29: one model client, chosen only by configuration, behind the
        // AI gateway (context budget, concurrency slots, metrics). There is no
        // fallback from one provider to another.
        $this->app->bind(ModelClient::class, static function ($app): ModelClient {
            $config = (array) $app['config']->get('codedna.ai');

            return match ($config['provider'] ?? null) {
                FakeModelClient::NAME => new FakeModelClient,
                OpenAiCompatibleProvider::NAME => new OpenAiCompatibleProvider($app->make(Http::class), $config),
                default => new OllamaClient(new HttpModelTransport($app->make(Http::class), $config), $config),
            };
        });
        $this->app->bind(AiProvider::class, static function ($app): AiProvider {
            $config = (array) $app['config']->get('codedna.ai');

            return ($config['provider'] ?? null) === FakeAiProvider::NAME
                ? new FakeAiProvider
                : new GatewayAiProvider($app->make(AiGateway::class), (int) $config['max_output_tokens']);
        });

        // Coding challenges (Phase 16): the server-owned catalog, and the only
        // path to code execution, the isolated evaluator. Never AI.
        $this->app->singleton(ChallengeCatalog::class, static fn ($app): ChallengeCatalog => ChallengeCatalog::forVersion(
            (string) $app['config']->get('codedna.challenges.catalog_version'),
        ));
        $this->app->bind(ChallengeEvaluator::class, static function ($app): ChallengeEvaluator {
            $config = (array) $app['config']->get('codedna.challenges');

            return ($config['evaluator'] ?? null) === 'spool'
                ? new SpoolChallengeEvaluator(
                    (string) $config['spool_path'],
                    (int) $config['wait_seconds'],
                    (int) $config['heartbeat_max_age_seconds'],
                    requiredIsolation: (string) $config['required_isolation'],
                )
                : new UnavailableChallengeEvaluator;
        });

        // GitHub integration (Phase 19): settings read from configuration on
        // every resolution, so nothing caches a secret beyond one request or job.
        $this->app->bind(GitHubSettings::class, static fn ($app): GitHubSettings => GitHubSettings::fromConfig($app['config']));

        // Billing (Phase 23): the plan catalog is read once per request or job;
        // the payment provider is the configured one only.
        $this->app->scoped(PlanCatalog::class);
        // Enterprise edition (Phase 27): the license is read and verified once
        // per request or job, so a renewal or expiry applies without a restart.
        $this->app->scoped(EnterpriseEdition::class);
        $this->app->singleton(ViewDecisions::class);
        $this->app->scoped(PaymentProviders::class, static fn ($app): PaymentProviders => new PaymentProviders((array) $app['config']->get('codedna.billing', [])));

        // Growth tracking (Phase 18): the deterministic, versioned rules.
        $this->app->singleton(GrowthRules::class, static fn ($app): GrowthRules => GrowthRules::forVersion(
            (string) $app['config']->get('codedna.growth.rules_version'),
        ));

        // Learning roadmaps (Phase 17): the server-owned track catalog and the
        // deterministic rules. No AI, no network.
        $this->app->singleton(RoadmapCatalog::class, static fn ($app): RoadmapCatalog => RoadmapCatalog::forVersion(
            (string) $app['config']->get('codedna.roadmap.catalog_version'),
            $app->make(ChallengeCatalog::class),
            $app->make(RoadmapRules::class),
        ));
        $this->app->singleton(RoadmapRules::class, static fn ($app): RoadmapRules => RoadmapRules::forVersion(
            (string) $app['config']->get('codedna.roadmap.rules_version'),
        ));
    }

    public function boot(): void
    {
        $this->validateConfiguration();

        $isProduction = $this->app->isProduction();

        // Lazy loading, silently discarded attributes and missing attributes
        // are bugs: fail loudly outside production.
        Model::shouldBeStrict(! $isProduction);
        // Refuse `migrate:fresh`, `db:wipe` and similar in production.
        DB::prohibitDestructiveCommands($isProduction);

        TrustProxies::at(config('codedna.trusted_proxies'));

        $this->refundFailedUsage();

        // Redis sessions that a request still running at logout cannot bring
        // back (Phase 22). Built like Laravel's own redis driver.
        Session::extend('redis', static function ($app): RedisSessionHandler {
            $handler = new RedisSessionHandler(clone $app['cache']->store(config('session.store') ?: 'redis'), (int) config('session.lifetime'));
            $store = $handler->getCache()->getStore();
            if ($store instanceof RedisStore) {
                $store->setConnection(config('session.connection'));
                if (is_string($prefix = config('session.prefix')) && $prefix !== '') {
                    $store->setPrefix($prefix);
                }
            }

            return $handler;
        });

        // bcrypt only uses the first 72 bytes of a password: longer ones
        // (also 72 characters of multibyte text) are refused rather than
        // silently truncated (Phase 21).
        Password::defaults(static fn (): Password => Password::min(8)->max(72)->rules([
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The :attribute must not be longer than 72 bytes.');
                }
            },
        ]));

        $this->configureRateLimiting();
    }

    /**
     * Billing (Phase 23): an accepted operation that ends without a result
     * gives its quota unit back, once (docs/billing/entitlements-and-quotas.md#refunds).
     */
    private function refundFailedUsage(): void
    {
        $refund = static function (QuotaKey $key, string $type, Model $model, bool $failed): void {
            if ($failed && $model->wasChanged('status')) {
                app(UsageService::class)->refund($key, $type, (string) $model->getKey());
            }
        };
        AnalysisRun::updated(static fn (AnalysisRun $run) => $refund(QuotaKey::Analyses, 'analysis_run', $run,
            in_array($run->status, [AnalysisRunStatus::Failed, AnalysisRunStatus::Cancelled], true)));
        AiAssessment::updated(static fn (AiAssessment $assessment) => $refund(QuotaKey::AiAssessments, 'ai_assessment', $assessment,
            $assessment->status === AssessmentStatus::Failed));
        // Phase 29: AI insights share the AI assessment quota.
        AiInsight::updated(static fn (AiInsight $insight) => $refund(QuotaKey::AiAssessments, 'ai_insight', $insight,
            $insight->status === AssessmentStatus::Failed));
        ChallengeSubmission::updated(static fn (ChallengeSubmission $submission) => $refund(QuotaKey::ChallengeSubmissions, 'challenge_submission', $submission,
            $submission->status === SubmissionStatus::Error));
        GitHubImport::updated(static fn (GitHubImport $import) => $refund(QuotaKey::GitHubImports, 'github_import', $import,
            $import->status === GitHubImportStatus::Failed));
        // Phase 28: GitLab and Bitbucket imports share the repository import quota.
        RepositoryProviderImport::updated(static fn (RepositoryProviderImport $import) => $refund(QuotaKey::GitHubImports, 'repository_import', $import,
            $import->status === GitHubImportStatus::Failed));
    }

    private function validateConfiguration(): void
    {
        $problems = (new ConfigurationValidator)->problems(
            $this->app->make('config'),
            (string) $this->app->environment(),
        );

        if ($problems !== []) {
            throw InvalidConfigurationException::withProblems($problems);
        }
    }

    private function configureRateLimiting(): void
    {
        $limits = config('codedna.rate_limits');

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute($limits['api_per_minute'])
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', static function (Request $request) use ($limits): array {
            // Runs before validation: a non-string email (e.g. a JSON array)
            // must not throw, it is simply keyed as empty.
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perMinute($limits['login_per_minute_per_email'])->by('login-email:'.$email.'|'.$request->ip()),
                Limit::perMinute($limits['login_per_minute_per_ip'])->by('login-ip:'.$request->ip()),
                Limit::perHour($limits['login_per_hour_per_email'])->by('login-account:'.$email),
            ];
        });

        RateLimiter::for('register', static fn (Request $request): Limit => Limit::perMinute($limits['register_per_minute_per_ip'])
            ->by('register-ip:'.$request->ip()));

        // Authenticated routes: keyed by user (they run after auth:sanctum).
        RateLimiter::for('profile-update', static fn (Request $request): Limit => Limit::perMinute($limits['profile_update_per_minute'])
            ->by('profile-update:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('project-create', static fn (Request $request): Limit => Limit::perMinute($limits['project_create_per_minute'])
            ->by('project-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('project-update', static fn (Request $request): Limit => Limit::perMinute($limits['project_update_per_minute'])
            ->by('project-update:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Uploads are expensive (inspection, hashing, storage): strict.
        RateLimiter::for('source-upload', static function (Request $request) use ($limits): array {
            $key = 'source-upload:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['source_upload_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['source_upload_per_hour'])->by($key.'|hour'),
            ];
        });

        RateLimiter::for('analysis-create', static fn (Request $request): Limit => Limit::perMinute($limits['analysis_create_per_minute'])
            ->by('analysis-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // AI assessments call a paid provider: strict.
        RateLimiter::for('assessment-create', static function (Request $request) use ($limits): array {
            $key = 'assessment-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['assessment_create_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['assessment_create_per_hour'])->by($key.'|hour'),
            ];
        });

        RateLimiter::for('challenge-assign', static fn (Request $request): Limit => Limit::perMinute($limits['challenge_assign_per_minute'])
            ->by('challenge-assign:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every submission is executed in the evaluator: strict.
        RateLimiter::for('challenge-submit', static function (Request $request) use ($limits): array {
            $key = 'challenge-submit:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['challenge_submit_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['challenge_submit_per_hour'])->by($key.'|hour'),
            ];
        });

        RateLimiter::for('roadmap-generate', static fn (Request $request): Limit => Limit::perMinute($limits['roadmap_generate_per_minute'])
            ->by('roadmap-generate:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('roadmap-progress', static fn (Request $request): Limit => Limit::perMinute($limits['roadmap_progress_per_minute'])
            ->by('roadmap-progress:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // GitHub (Phase 19): every call reaches GitHub; imports download a whole archive.
        RateLimiter::for('github-authorize', static fn (Request $request): Limit => Limit::perMinute($limits['github_authorize_per_minute'])
            ->by('github-authorize:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('github-read', static fn (Request $request): Limit => Limit::perMinute($limits['github_read_per_minute'])
            ->by('github-read:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('github-write', static fn (Request $request): Limit => Limit::perMinute($limits['github_write_per_minute'])
            ->by('github-write:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('billing-read', static fn (Request $request): Limit => Limit::perMinute($limits['billing_read_per_minute'])
            ->by('billing-read:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Per provider and IP: never keyed by anything a sender controls in the body.
        RateLimiter::for('billing-webhook', static fn (Request $request): Limit => Limit::perMinute($limits['billing_webhook_per_minute'])
            ->by('billing-webhook:'.(string) $request->route('provider').'|'.$request->ip()));

        RateLimiter::for('github-import', static function (Request $request) use ($limits): array {
            $key = 'github-import:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['github_import_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['github_import_per_hour'])->by($key.'|hour'),
            ];
        });

        RateLimiter::for('organization-create', static function (Request $request) use ($limits): array {
            $key = 'organization-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['organization_create_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['organization_create_per_hour'])->by($key.'|hour'),
            ];
        });
        RateLimiter::for('organization-write', static fn (Request $request): Limit => Limit::perMinute($limits['organization_write_per_minute'])
            ->by('organization-write:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('invitation-create', static function (Request $request) use ($limits): array {
            $key = 'invitation-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['invitation_create_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['invitation_create_per_hour'])->by($key.'|hour'),
            ];
        });
        RateLimiter::for('invitation-accept', static fn (Request $request): array => [
            Limit::perMinute($limits['invitation_accept_per_minute'])->by('invitation-accept:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perMinute($limits['invitation_accept_per_minute_per_ip'])->by('invitation-accept-ip:'.$request->ip()),
        ]);
        RateLimiter::for('invitation-preview', static fn (Request $request): Limit => Limit::perMinute($limits['invitation_preview_per_minute_per_ip'])
            ->by('invitation-preview:'.$request->ip()));
        RateLimiter::for('password-change', static function (Request $request) use ($limits): array {
            $key = 'password-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['password_change_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['password_change_per_hour'])->by($key.'|hour'),
            ];
        });
    }
}
