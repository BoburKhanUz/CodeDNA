<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ConfigurationValidator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class ConfigurationValidatorTest extends TestCase
{
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
}
