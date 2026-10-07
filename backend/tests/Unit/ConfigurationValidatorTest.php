<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ConfigurationValidator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class ConfigurationValidatorTest extends TestCase
{
    private const AI = [
        'enabled' => false, 'provider' => 'openai_compatible', 'model' => '', 'base_url' => 'https://api.openai.com/v1',
        'api_key' => '', 'structured_output' => 'json_schema', 'connect_timeout_seconds' => 5, 'timeout_seconds' => 60,
        'max_input_bytes' => 32768, 'max_output_bytes' => 16384, 'max_output_tokens' => 2000, 'max_attempts' => 3,
        'job_timeout_seconds' => 90, 'stale_after_seconds' => 900, 'queued_stale_after_seconds' => 3600,
        'queue_connection' => 'analysis', 'version' => '1.0.0',
    ];

    private const CHALLENGES = [
        'enabled' => true, 'catalog_version' => '1.0.0', 'evaluator' => 'spool', 'spool_path' => '/var/spool/codedna-challenges',
        'wait_seconds' => 45, 'max_source_bytes' => 16384, 'max_source_lines' => 400, 'max_attempts' => 5, 'job_timeout_seconds' => 90,
        'queue_connection' => 'analysis',
    ];

    /**
     * @param  array<string, mixed>  $challenges
     * @return list<string>
     */
    private function challengeProblems(array $challenges): array
    {
        return (new ConfigurationValidator)->problems($this->config(['codedna.challenges' => $challenges + self::CHALLENGES]), 'production');
    }

    /**
     * @param  array<string, mixed>  $ai
     * @return list<string>
     */
    private function aiProblems(array $ai, string $environment = 'production'): array
    {
        return (new ConfigurationValidator)->problems($this->config(['codedna.ai' => $ai + self::AI]), $environment);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): Repository
    {
        $config = new Repository;
        foreach (array_merge([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.debug' => false,
            'app.url' => 'https://app.codedna.example',
            'app.timezone' => 'UTC',
            'database.default' => 'pgsql',
            'session.driver' => 'redis',
            'session.secure' => true,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'sanctum.stateful' => ['app.codedna.example'],
            'codedna.sources.key_prefix' => '',
            'codedna.sources.limits' => [
                'archive_bytes' => 52428800, 'uncompressed_bytes' => 209715200, 'files' => 20000,
                'single_file_bytes' => 26214400, 'path_length' => 512,
            ],
            'codedna.analyzer' => [
                'url' => 'http://analyzer:8000', 'hmac_secret' => str_repeat('s', 64), 'hmac_secret_previous' => '',
                'hard_timeout_seconds' => 240, 'timeout_seconds' => 300, 'connect_timeout_seconds' => 5,
                'max_attempts' => 3, 'source_url_ttl_seconds' => 900,
            ],
            'codedna.analysis' => ['job_timeout_seconds' => 330],
            'queue.connections.analysis.retry_after' => 360,
            'codedna.scoring.version' => '1.0.0',
            'codedna.competency.version' => '1.0.0',
            'codedna.skill_gap.version' => '1.0.0',
            'codedna.roadmap.catalog_version' => '1.0.0',
            'codedna.roadmap.rules_version' => '1.0.0',
            'codedna.growth.rules_version' => '1.0.0',
            'codedna.ai' => self::AI,
            'codedna.challenges' => self::CHALLENGES,
        ], $overrides) as $key => $value) {
            $config->set($key, $value);
        }

        return $config;
    }

    public function test_a_complete_production_configuration_is_valid(): void
    {
        $this->assertSame([], (new ConfigurationValidator)->problems($this->config(), 'production'));
    }

    public function test_requires_an_application_key_and_postgresql(): void
    {
        $problems = (new ConfigurationValidator)->problems(
            $this->config(['app.key' => '', 'database.default' => 'sqlite']),
            'local',
        );

        $this->assertContains('APP_KEY is not set.', $problems);
        $this->assertContains('DB_CONNECTION must be "pgsql" (CodeDNA requires PostgreSQL).', $problems);
    }

    public function test_requires_utc_because_timestamps_are_stored_without_a_zone(): void
    {
        $this->assertSame(
            ['The application timezone must be UTC.'],
            (new ConfigurationValidator)->problems($this->config(['app.timezone' => 'Europe/Berlin']), 'local'),
        );
    }

    public function test_requires_redis_drivers_outside_the_test_suite(): void
    {
        $config = $this->config(['session.driver' => 'database', 'cache.default' => 'array', 'queue.default' => 'sync']);
        $validator = new ConfigurationValidator;

        $this->assertSame([
            'SESSION_DRIVER must be "redis".',
            'CACHE_STORE must be "redis".',
            'QUEUE_CONNECTION must be "redis".',
        ], $validator->problems($config, 'local'));
        $this->assertSame([], $validator->problems($config, 'testing'));
    }

    public function test_rejects_unsafe_production_settings(): void
    {
        $problems = (new ConfigurationValidator)->problems($this->config([
            'app.debug' => true,
            'app.url' => 'http://app.codedna.example',
            'session.secure' => false,
            'sanctum.stateful' => [''],
        ]), 'production');

        $this->assertSame([
            'APP_DEBUG must be false in production.',
            'APP_URL must use https:// in production.',
            'SESSION_SECURE_COOKIE must be true in production.',
            'SANCTUM_STATEFUL_DOMAINS must list the production frontend domain.',
        ], $problems);
    }

    public function test_production_rules_do_not_apply_to_local_development(): void
    {
        $this->assertSame([], (new ConfigurationValidator)->problems($this->config([
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'session.secure' => false,
        ]), 'local'));
    }

    public function test_source_upload_limits_must_be_positive_and_consistent(): void
    {
        $problems = (new ConfigurationValidator)->problems($this->config([
            'codedna.sources.limits' => [
                'archive_bytes' => 0, 'uncompressed_bytes' => 1000, 'files' => 10,
                'single_file_bytes' => 2000, 'path_length' => 512,
            ],
        ]), 'local');

        $this->assertContains('The source upload limit "archive_bytes" must be a positive integer.', $problems);
        $this->assertContains('SOURCE_MAX_SINGLE_FILE_BYTES must not exceed SOURCE_MAX_UNCOMPRESSED_BYTES.', $problems);
    }

    public function test_the_storage_prefix_cannot_escape_the_key_layout(): void
    {
        foreach (['../', '/abs/', 'no-slash', 'Upper/', 'a/../b/'] as $prefix) {
            $this->assertContains(
                'SOURCE_STORAGE_PREFIX must be empty or lowercase path segments ending in "/" (e.g. "staging/").',
                (new ConfigurationValidator)->problems($this->config(['codedna.sources.key_prefix' => $prefix]), 'local'),
                $prefix,
            );
        }
        foreach (['', 'phpunit/', 'staging/eu-1/'] as $prefix) {
            $this->assertSame([], (new ConfigurationValidator)->problems($this->config(['codedna.sources.key_prefix' => $prefix]), 'production'), $prefix);
        }
    }

    public function test_the_analyzer_timeout_chain_must_let_inner_limits_fire_first(): void
    {
        $analyzer = $this->config()->get('codedna.analyzer');
        $problems = (new ConfigurationValidator)->problems(
            $this->config(['codedna.analyzer' => ['timeout_seconds' => 240] + $analyzer, 'queue.connections.analysis.retry_after' => 90]),
            'local',
        );

        $this->assertContains('ANALYZER_HARD_TIMEOUT_SECONDS must be lower than ANALYZER_TIMEOUT_SECONDS.', $problems);
        $this->assertContains('ANALYSIS_JOB_TIMEOUT_SECONDS must be lower than ANALYSIS_QUEUE_RETRY_AFTER.', $problems);
    }

    public function test_the_analyzer_needs_a_secret_an_internal_url_and_bounded_settings(): void
    {
        $analyzer = $this->config()->get('codedna.analyzer');
        $problems = (new ConfigurationValidator)->problems($this->config(['codedna.analyzer' => [
            'url' => 'http://analyzer:8000/evil?x=1', 'hmac_secret' => 'short', 'hmac_secret_previous' => 'short',
            'max_attempts' => 0, 'source_url_ttl_seconds' => 7200,
        ] + $analyzer]), 'production');

        $this->assertContains('ANALYZER_URL must be an http(s) origin without a path (e.g. http://analyzer:8000).', $problems);
        $this->assertContains('ANALYZER_HMAC_SECRET must be at least 32 characters (64 hex characters recommended).', $problems);
        $this->assertContains('ANALYZER_HMAC_SECRET_PREVIOUS must be empty or at least 32 characters.', $problems);
        $this->assertContains('ANALYZER_MAX_ATTEMPTS must be between 1 and 10.', $problems);
        $this->assertContains('SOURCE_URL_TTL_SECONDS must be between 60 and 3600 (the analyzer accepts at most 3600).', $problems);
    }

    public function test_the_scoring_version_must_be_a_defined_specification(): void
    {
        foreach (['1.0', '1.0.1', '2.0.0', '', null] as $version) {
            $this->assertSame(
                ['CODEDNA_SCORING_VERSION must be one of: 1.0.0.'],
                (new ConfigurationValidator)->problems($this->config(['codedna.scoring.version' => $version]), 'production'),
            );
        }
    }

    public function test_the_competency_version_must_be_a_defined_specification(): void
    {
        foreach (['1.0', '2.0.0', '', null] as $version) {
            $this->assertSame(
                ['CODEDNA_COMPETENCY_VERSION must be one of: 1.0.0.'],
                (new ConfigurationValidator)->problems($this->config(['codedna.competency.version' => $version]), 'production'),
            );
        }
    }

    public function test_the_roadmap_versions_must_be_defined(): void
    {
        $this->assertSame([], (new ConfigurationValidator)->problems($this->config(), 'production'));
        foreach (['2.0.0', '', null] as $version) {
            $this->assertSame(
                ['CODEDNA_ROADMAP_VERSION must be one of: 1.0.0.'],
                (new ConfigurationValidator)->problems($this->config(['codedna.roadmap.catalog_version' => $version]), 'production'),
            );
            $this->assertSame(
                ['CODEDNA_ROADMAP_RULES_VERSION must be one of: 1.0.0.'],
                (new ConfigurationValidator)->problems($this->config(['codedna.roadmap.rules_version' => $version]), 'production'),
            );
        }
    }

    public function test_the_skill_gap_version_must_be_a_defined_specification(): void
    {
        foreach (['1.0', '2.0.0', '', null] as $version) {
            $this->assertSame(
                ['CODEDNA_SKILL_GAP_VERSION must be one of: 1.0.0.'],
                (new ConfigurationValidator)->problems($this->config(['codedna.skill_gap.version' => $version]), 'production'),
            );
        }
    }

    public function test_the_growth_rules_version_must_be_defined(): void
    {
        $this->assertSame([], (new ConfigurationValidator)->problems($this->config(), 'production'));
        foreach (['1.0', '2.0.0', '', null] as $version) {
            $this->assertSame(
                ['CODEDNA_GROWTH_RULES_VERSION must be one of: 1.0.0.'],
                (new ConfigurationValidator)->problems($this->config(['codedna.growth.rules_version' => $version]), 'production'),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function github(array $overrides = []): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return ['codedna.github' => $overrides + [
            'app_id' => '1', 'app_slug' => 'codedna', 'client_id' => 'Iv1.x', 'client_secret' => 'secret', 'private_key' => (string) $pem,
            'private_key_path' => '', 'api_url' => 'https://api.github.com', 'web_url' => 'https://github.com',
            'archive_origins' => ['https://codeload.github.com'], 'callback_url' => 'https://app.codedna.example/app/github/callback',
            'state_ttl_seconds' => 600, 'connect_timeout_seconds' => 5, 'timeout_seconds' => 15, 'download_timeout_seconds' => 120,
            'max_response_bytes' => 4194304, 'retry_delay_ms' => 250, 'queue_connection' => 'analysis', 'queue' => 'github',
            'job_timeout_seconds' => 180, 'import_stale_after_seconds' => 900,
        ]];
    }

    public function test_github_is_optional_and_fully_configured_or_not_at_all(): void
    {
        $validator = new ConfigurationValidator;
        $this->assertSame([], $validator->problems($this->config(self::github(['app_id' => '', 'app_slug' => '', 'client_id' => '', 'client_secret' => '', 'private_key' => ''])), 'production'));
        $this->assertSame([], $validator->problems($this->config(self::github()), 'production'));
        $this->assertSame(
            ['GitHub App configuration is incomplete: set GITHUB_APP_CLIENT_SECRET, GITHUB_APP_PRIVATE_KEY or GITHUB_APP_PRIVATE_KEY_PATH.'],
            $validator->problems($this->config(self::github(['client_secret' => '', 'private_key' => ''])), 'production'),
        );
    }

    public function test_github_needs_a_real_key_https_urls_and_bare_origins_in_production(): void
    {
        $validator = new ConfigurationValidator;
        $this->assertSame(['GITHUB_APP_PRIVATE_KEY is not a readable PEM private key.'], $validator->problems($this->config(self::github(['private_key' => 'not a key'])), 'production'));
        $this->assertSame(['GITHUB_API_URL must be an absolute https URL.'], $validator->problems($this->config(self::github(['api_url' => 'http://api.github.com'])), 'production'));
        $this->assertSame([], $validator->problems($this->config(self::github(['api_url' => 'http://github-double:8080'])), 'local'));
        $this->assertSame(['GITHUB_WEB_URL must be an absolute https URL.'], $validator->problems($this->config(self::github(['web_url' => 'github.com'])), 'production'));
        foreach (['https://codeload.github.com/path', 'http://codeload.github.com', 'https://user:pw@codeload.github.com', 'https://codeload.github.com?x=1'] as $origin) {
            $this->assertSame(['GITHUB_ARCHIVE_ORIGINS entries must be origins (scheme://host[:port]) without a path, using https.'],
                $validator->problems($this->config(self::github(['archive_origins' => [$origin]])), 'production'), $origin);
        }
        $this->assertSame(['GITHUB_ARCHIVE_ORIGINS must list at least one origin.'], $validator->problems($this->config(self::github(['archive_origins' => []])), 'production'));
        $this->assertSame(["GITHUB_APP_SLUG must be the GitHub App's URL slug."], $validator->problems($this->config(self::github(['app_slug' => '../x'])), 'production'));
        $this->assertSame(['GitHub timeouts must be positive and GITHUB_IMPORT_JOB_TIMEOUT_SECONDS must exceed the download and request timeouts.'],
            $validator->problems($this->config(self::github(['job_timeout_seconds' => 130])), 'production'));
        $this->assertSame(['GITHUB_IMPORT_JOB_TIMEOUT_SECONDS must be lower than the queue retry_after.'],
            $validator->problems($this->config(self::github(['job_timeout_seconds' => 400])), 'production'));
    }

    public function test_ai_is_disabled_by_default_and_needs_nothing_then(): void
    {
        $this->assertSame([], $this->aiProblems([]));
    }

    public function test_an_enabled_provider_needs_a_model_and_an_https_url_in_production(): void
    {
        $this->assertSame(['AI_MODEL must be set to a model identifier when AI is enabled.'], $this->aiProblems(['enabled' => true]));
        $this->assertSame([], $this->aiProblems(['enabled' => true, 'model' => 'gpt-4o-mini']));
        $this->assertSame([], $this->aiProblems(['enabled' => true, 'model' => 'org/model:7b', 'base_url' => 'https://llm.internal.example:8443/v1']));

        foreach (['http://llm.example/v1', 'https://user:pass@llm.example/v1', 'https://llm.example/v1?key=x', 'ftp://llm.example', ''] as $url) {
            $this->assertSame(
                ['AI_BASE_URL must be an https:// URL without query or credentials in production.'],
                $this->aiProblems(['enabled' => true, 'model' => 'm', 'base_url' => $url]),
                $url,
            );
        }
        $this->assertSame(['AI_MODEL must be set to a model identifier when AI is enabled.'], $this->aiProblems(['enabled' => true, 'model' => "m\nx"]));
    }

    public function test_local_providers_may_use_http_outside_production(): void
    {
        $this->assertSame([], $this->aiProblems(['enabled' => true, 'model' => 'llama3.1:8b', 'base_url' => 'http://host.docker.internal:11434/v1', 'structured_output' => 'json_object'], 'local'));
        $this->assertSame(['AI_BASE_URL must be an http(s):// URL without query or credentials.'], $this->aiProblems(['enabled' => true, 'model' => 'm', 'base_url' => 'file:///etc/passwd'], 'local'));
    }

    public function test_the_fake_provider_is_refused_in_production(): void
    {
        $this->assertSame(['AI_PROVIDER "fake" is not allowed in production.'], $this->aiProblems(['enabled' => true, 'provider' => 'fake']));
        $this->assertSame([], $this->aiProblems(['enabled' => true, 'provider' => 'fake'], 'local'));
        $this->assertSame(['AI_PROVIDER must be "openai_compatible" or "fake".'], $this->aiProblems(['provider' => 'anthropic-sdk']));
    }

    public function test_ai_settings_are_validated(): void
    {
        $this->assertSame(['CODEDNA_ASSESSMENT_VERSION must be one of: 1.0.0.'], $this->aiProblems(['version' => '2.0.0']));
        $this->assertSame(['AI_ENABLED must be true or false.'], $this->aiProblems(['enabled' => 'yes']));
        $this->assertSame(['AI_STRUCTURED_OUTPUT must be one of: json_schema, json_object, none.'], $this->aiProblems(['structured_output' => 'tools']));
        $this->assertSame(['AI_MAX_ATTEMPTS must be an integer between 1 and 5.'], $this->aiProblems(['max_attempts' => 0]));
        $this->assertSame(['AI_MAX_ATTEMPTS must be an integer between 1 and 5.'], $this->aiProblems(['max_attempts' => 10]));
        $this->assertSame(['AI_MAX_INPUT_BYTES must be an integer between 1024 and 262144.'], $this->aiProblems(['max_input_bytes' => 10_000_000]));
        $this->assertSame(['AI_MAX_OUTPUT_TOKENS must be an integer between 256 and 32768.'], $this->aiProblems(['max_output_tokens' => 100]));
    }

    public function test_the_ai_timeout_chain_must_let_inner_limits_fire_first(): void
    {
        $this->assertSame(['AI_JOB_TIMEOUT_SECONDS must be greater than AI_TIMEOUT_SECONDS.'], $this->aiProblems(['timeout_seconds' => 90]));
        $this->assertSame(['AI_JOB_TIMEOUT_SECONDS must be lower than the queue retry_after.'], $this->aiProblems(['timeout_seconds' => 300, 'job_timeout_seconds' => 360]));
    }

    public function test_challenge_settings_are_validated(): void
    {
        $this->assertSame([], $this->challengeProblems([]));
        $this->assertSame([], $this->challengeProblems(['evaluator' => 'none', 'enabled' => false]));
        $this->assertSame(['CHALLENGE_EVALUATOR must be "spool" or "none".'], $this->challengeProblems(['evaluator' => 'host']));
        $this->assertSame(['CHALLENGE_ENABLED must be true or false.'], $this->challengeProblems(['enabled' => 'yes']));
        $this->assertSame(['CODEDNA_CHALLENGE_CATALOG_VERSION must be one of: 1.0.0.'], $this->challengeProblems(['catalog_version' => '2.0.0']));
        $this->assertSame(['CHALLENGE_EVALUATOR_SPOOL must be an absolute path.'], $this->challengeProblems(['spool_path' => '../spool']));
        $this->assertSame(['CHALLENGE_EVALUATOR_SPOOL must be an absolute path.'], $this->challengeProblems(['spool_path' => '/var/../etc']));
        $this->assertSame(['CHALLENGE_MAX_SOURCE_BYTES must be an integer between 256 and 65536.'], $this->challengeProblems(['max_source_bytes' => 1048576]));
        $this->assertSame(['CHALLENGE_MAX_ATTEMPTS must be an integer between 1 and 20.'], $this->challengeProblems(['max_attempts' => 0]));
        $this->assertSame(['CHALLENGE_MAX_SOURCE_LINES must be an integer between 10 and 5000.'], $this->challengeProblems(['max_source_lines' => 1]));
    }

    public function test_the_challenge_timeout_chain_must_let_inner_limits_fire_first(): void
    {
        $this->assertSame(['CHALLENGE_JOB_TIMEOUT_SECONDS must be greater than CHALLENGE_EVALUATOR_WAIT_SECONDS.'], $this->challengeProblems(['wait_seconds' => 90]));
        $this->assertSame(['CHALLENGE_JOB_TIMEOUT_SECONDS must be lower than the queue retry_after.'], $this->challengeProblems(['job_timeout_seconds' => 360]));
    }

    /**
     * Phase 21: production checks apply to every deployed environment, not
     * only to one spelled exactly "production".
     */
    public function test_every_environment_except_local_and_testing_gets_production_checks(): void
    {
        foreach (['staging', 'prod', 'Production', 'qa'] as $environment) {
            $this->assertContains('AI_PROVIDER "fake" is not allowed in production.', $this->aiProblems(['enabled' => true, 'provider' => 'fake'], $environment), $environment);
            $this->assertContains('APP_DEBUG must be false in production.', (new ConfigurationValidator)->problems($this->config(['app.debug' => true]), $environment), $environment);
        }
        $this->assertSame([], $this->aiProblems(['enabled' => true, 'provider' => 'fake'], 'local'));
        $this->assertNotContains('APP_DEBUG must be false in production.', (new ConfigurationValidator)->problems($this->config(['app.debug' => true]), 'local'));
    }

    /** Phase 21: credentialed CORS never allows a wildcard or a pattern. */
    public function test_cors_origins_must_be_exact(): void
    {
        $message = 'CORS_ALLOWED_ORIGINS must list exact origins (scheme://host[:port]); wildcards are not allowed.';
        foreach ([['*'], ['https://*.example.com'], ['https://app.example.com/path'], ['null']] as $origins) {
            $this->assertContains($message, (new ConfigurationValidator)->problems($this->config(['cors.allowed_origins' => $origins]), 'production'), json_encode($origins));
        }
        $this->assertContains('CORS origin patterns are not allowed.', (new ConfigurationValidator)->problems($this->config(['cors.allowed_origins_patterns' => ['~.*~']]), 'production'));
        $this->assertSame([], (new ConfigurationValidator)->problems($this->config(['cors.allowed_origins' => ['https://app.codedna.example', 'http://localhost:3000']]), 'production'));
    }
}
