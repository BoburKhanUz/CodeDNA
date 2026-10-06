<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Dna\ScoringSpecification;
use Illuminate\Contracts\Config\Repository;

/**
 * Validates configuration that CodeDNA depends on, so misconfiguration fails
 * at boot with a clear message instead of surfacing later as odd behavior.
 */
final class ConfigurationValidator
{
    /**
     * @return list<string> problems found (empty when the configuration is valid)
     */
    public function problems(Repository $config, string $environment): array
    {
        $problems = [];

        if (! is_string($config->get('app.key')) || $config->get('app.key') === '') {
            $problems[] = 'APP_KEY is not set.';
        }

        if ($config->get('database.default') !== 'pgsql') {
            $problems[] = 'DB_CONNECTION must be "pgsql" (CodeDNA requires PostgreSQL).';
        }

        // Domain timestamps are stored as UTC without a zone (docs/architecture/data-model.md).
        if ($config->get('app.timezone') !== 'UTC') {
            $problems[] = 'The application timezone must be UTC.';
        }

        $problems = [...$problems, ...$this->sourceStorageProblems($config), ...$this->analyzerProblems($config, $environment)];

        if (! in_array($config->get('codedna.scoring.version'), ScoringSpecification::VERSIONS, true)) {
            $problems[] = 'CODEDNA_SCORING_VERSION must be one of: '.implode(', ', ScoringSpecification::VERSIONS).'.';
        }

        // The test suite swaps in in-memory drivers; every other environment
        // must use the Redis-backed infrastructure (docs/architecture/backend.md).
        if ($environment !== 'testing') {
            foreach (['session.driver' => 'SESSION_DRIVER', 'cache.default' => 'CACHE_STORE', 'queue.default' => 'QUEUE_CONNECTION'] as $key => $variable) {
                if ($config->get($key) !== 'redis') {
                    $problems[] = "{$variable} must be \"redis\".";
                }
            }
        }

        if ($environment === 'production') {
            if ($config->get('app.debug') === true) {
                $problems[] = 'APP_DEBUG must be false in production.';
            }

            if (! str_starts_with((string) $config->get('app.url'), 'https://')) {
                $problems[] = 'APP_URL must use https:// in production.';
            }

            if ($config->get('session.secure') !== true) {
                $problems[] = 'SESSION_SECURE_COOKIE must be true in production.';
            }

            if (array_filter((array) $config->get('sanctum.stateful')) === []) {
                $problems[] = 'SANCTUM_STATEFUL_DOMAINS must list the production frontend domain.';
            }
        }

        return $problems;
    }

    /**
     * The analysis pipeline (Phase 10). The timeout chain makes inner limits
     * fire first (ADR-005): analyzer hard limit < HTTP timeout < job timeout
     * < queue retry_after, so a job is never killed or handed to a second
     * worker while the analyzer may still answer.
     *
     * @return list<string>
     */
    private function analyzerProblems(Repository $config, string $environment): array
    {
        $problems = [];
        $analyzer = (array) $config->get('codedna.analyzer', []);
        $analysis = (array) $config->get('codedna.analysis', []);

        $url = $analyzer['url'] ?? null;
        if (! is_string($url) || preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?$#', $url) !== 1) {
            $problems[] = 'ANALYZER_URL must be an http(s) origin without a path (e.g. http://analyzer:8000).';
        }

        if ($environment !== 'testing') {
            foreach (['hmac_secret' => 'ANALYZER_HMAC_SECRET'] as $key => $variable) {
                if (! is_string($analyzer[$key] ?? null) || strlen($analyzer[$key]) < 32) {
                    $problems[] = "{$variable} must be at least 32 characters (64 hex characters recommended).";
                }
            }
        }
        $previous = $analyzer['hmac_secret_previous'] ?? '';
        if (is_string($previous) && $previous !== '' && strlen($previous) < 32) {
            $problems[] = 'ANALYZER_HMAC_SECRET_PREVIOUS must be empty or at least 32 characters.';
        }

        $chain = [
            'ANALYZER_HARD_TIMEOUT_SECONDS' => $analyzer['hard_timeout_seconds'] ?? null,
            'ANALYZER_TIMEOUT_SECONDS' => $analyzer['timeout_seconds'] ?? null,
            'ANALYSIS_JOB_TIMEOUT_SECONDS' => $analysis['job_timeout_seconds'] ?? null,
            'ANALYSIS_QUEUE_RETRY_AFTER' => $config->get('queue.connections.analysis.retry_after'),
        ];
        foreach ($chain as $variable => $value) {
            if (! is_int($value) || $value < 1) {
                $problems[] = "{$variable} must be a positive integer.";
            }
        }
        $values = array_values($chain);
        $names = array_keys($chain);
        for ($i = 1; $i < count($values); $i++) {
            if (is_int($values[$i - 1]) && is_int($values[$i]) && $values[$i - 1] >= $values[$i]) {
                $problems[] = "{$names[$i - 1]} must be lower than {$names[$i]}.";
            }
        }

        $attempts = $analyzer['max_attempts'] ?? null;
        if (! is_int($attempts) || $attempts < 1 || $attempts > 10) {
            $problems[] = 'ANALYZER_MAX_ATTEMPTS must be between 1 and 10.';
        }
        $ttl = $analyzer['source_url_ttl_seconds'] ?? null;
        if (! is_int($ttl) || $ttl < 60 || $ttl > 3600) {
            $problems[] = 'SOURCE_URL_TTL_SECONDS must be between 60 and 3600 (the analyzer accepts at most 3600).';
        }
        $connect = $analyzer['connect_timeout_seconds'] ?? null;
        if (! is_int($connect) || $connect < 1) {
            $problems[] = 'ANALYZER_CONNECT_TIMEOUT_SECONDS must be a positive integer.';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function sourceStorageProblems(Repository $config): array
    {
        $problems = [];
        $limits = (array) $config->get('codedna.sources.limits', []);

        foreach (['archive_bytes', 'uncompressed_bytes', 'files', 'single_file_bytes', 'path_length'] as $limit) {
            if (! is_int($limits[$limit] ?? null) || $limits[$limit] < 1) {
                $problems[] = "The source upload limit \"{$limit}\" must be a positive integer.";
            }
        }

        if (is_int($limits['single_file_bytes'] ?? null) && is_int($limits['uncompressed_bytes'] ?? null)
            && $limits['single_file_bytes'] > $limits['uncompressed_bytes']) {
            $problems[] = 'SOURCE_MAX_SINGLE_FILE_BYTES must not exceed SOURCE_MAX_UNCOMPRESSED_BYTES.';
        }

        // Keys are server-generated; a prefix must not be able to escape the bucket layout.
        $prefix = $config->get('codedna.sources.key_prefix');
        if (! is_string($prefix) || ($prefix !== '' && preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*/$#', $prefix) !== 1)) {
            $problems[] = 'SOURCE_STORAGE_PREFIX must be empty or lowercase path segments ending in "/" (e.g. "staging/").';
        }

        return $problems;
    }
}
