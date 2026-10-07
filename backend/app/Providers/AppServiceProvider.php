<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\InvalidConfigurationException;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Assessment\Provider\FakeAiProvider;
use App\Services\Assessment\Provider\OpenAiCompatibleProvider;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use App\Services\Challenge\Evaluator\SpoolChallengeEvaluator;
use App\Services\Challenge\Evaluator\UnavailableChallengeEvaluator;
use App\Support\ConfigurationValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The AI provider behind the assessment pipeline (Phase 15), chosen
        // only by configuration (docs/architecture/ai-assessment-v1.md#providers).
        $this->app->bind(AiProvider::class, static function ($app): AiProvider {
            $config = (array) $app['config']->get('codedna.ai');

            return ($config['provider'] ?? null) === FakeAiProvider::NAME
                ? new FakeAiProvider
                : new OpenAiCompatibleProvider($app->make(Http::class), $config);
        });

        // Coding challenges (Phase 16): the server-owned catalog, and the only
        // path to code execution, the isolated evaluator. Never AI.
        $this->app->singleton(ChallengeCatalog::class, static fn ($app): ChallengeCatalog => ChallengeCatalog::forVersion(
            (string) $app['config']->get('codedna.challenges.catalog_version'),
        ));
        $this->app->bind(ChallengeEvaluator::class, static function ($app): ChallengeEvaluator {
            $config = (array) $app['config']->get('codedna.challenges');

            return ($config['evaluator'] ?? null) === 'spool'
                ? new SpoolChallengeEvaluator((string) $config['spool_path'], (int) $config['wait_seconds'], (int) $config['heartbeat_max_age_seconds'])
                : new UnavailableChallengeEvaluator;
        });
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

        // bcrypt only uses the first 72 bytes of a password.
        Password::defaults(static fn (): Password => Password::min(8)->max(72));

        $this->configureRateLimiting();
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

        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute($limits['login_per_minute_per_email'])
                ->by('login-email:'.mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()),
            Limit::perMinute($limits['login_per_minute_per_ip'])->by('login-ip:'.$request->ip()),
        ]);

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

        RateLimiter::for('password-change', static function (Request $request) use ($limits): array {
            $key = 'password-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return [
                Limit::perMinute($limits['password_change_per_minute'])->by($key.'|minute'),
                Limit::perHour($limits['password_change_per_hour'])->by($key.'|hour'),
            ];
        });
    }
}
