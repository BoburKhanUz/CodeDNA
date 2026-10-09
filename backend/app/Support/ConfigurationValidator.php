<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Assessment\AssessmentSpecification;
use App\Services\Assessment\Provider\FakeAiProvider;
use App\Services\Assessment\Provider\OpenAiCompatibleProvider;
use App\Services\Billing\Provider\FakePaymentProvider;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\Evaluator\SpoolChallengeEvaluator;
use App\Services\Competency\CompetencySpecification;
use App\Services\Dna\ScoringSpecification;
use App\Services\GitHub\GitHubSettings;
use App\Services\Growth\GrowthRules;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapRules;
use App\Services\SkillGap\SkillGapSpecification;
use Illuminate\Contracts\Config\Repository;

/**
 * Validates configuration that CodeDNA depends on, so misconfiguration fails
 * at boot with a clear message instead of surfacing later as odd behavior.
 */
final class ConfigurationValidator
{
    /**
     * Every environment except local development and the test suite gets
     * the production checks (Phase 21): a "staging" or "prod" deployment is
     * not exempt from https, secure cookies, APP_DEBUG=false or the ban on
     * the fake AI provider.
     */
    public static function isDeployed(string $environment): bool
    {
        return ! in_array($environment, ['local', 'testing'], true);
    }

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

        $problems = [...$problems, ...$this->sourceStorageProblems($config), ...$this->analyzerProblems($config, $environment), ...$this->githubProblems($config, $environment)];

        if (! in_array($config->get('codedna.scoring.version'), ScoringSpecification::VERSIONS, true)) {
            $problems[] = 'CODEDNA_SCORING_VERSION must be one of: '.implode(', ', ScoringSpecification::VERSIONS).'.';
        }
        if (! in_array($config->get('codedna.competency.version'), CompetencySpecification::VERSIONS, true)) {
            $problems[] = 'CODEDNA_COMPETENCY_VERSION must be one of: '.implode(', ', CompetencySpecification::VERSIONS).'.';
        }
        if (! in_array($config->get('codedna.skill_gap.version'), SkillGapSpecification::VERSIONS, true)) {
            $problems[] = 'CODEDNA_SKILL_GAP_VERSION must be one of: '.implode(', ', SkillGapSpecification::VERSIONS).'.';
        }
        if (! in_array($config->get('codedna.growth.rules_version'), GrowthRules::VERSIONS, true)) {
            $problems[] = 'CODEDNA_GROWTH_RULES_VERSION must be one of: '.implode(', ', GrowthRules::VERSIONS).'.';
        }
        if (! in_array($config->get('codedna.roadmap.catalog_version'), RoadmapCatalog::VERSIONS, true)) {
            $problems[] = 'CODEDNA_ROADMAP_VERSION must be one of: '.implode(', ', RoadmapCatalog::VERSIONS).'.';
        }
        if (! in_array($config->get('codedna.roadmap.rules_version'), RoadmapRules::VERSIONS, true)) {
            $problems[] = 'CODEDNA_ROADMAP_RULES_VERSION must be one of: '.implode(', ', RoadmapRules::VERSIONS).'.';
        }
        $problems = [...$problems, ...$this->aiProblems($config, $environment), ...$this->challengeProblems($config), ...$this->billingProblems($config, $environment)];
        $problems = [...$problems, ...$this->selfHostedProblems($config)];

        // The test suite swaps in in-memory drivers; every other environment
        // must use the Redis-backed infrastructure (docs/architecture/backend.md).
        if ($environment !== 'testing') {
            foreach (['session.driver' => 'SESSION_DRIVER', 'cache.default' => 'CACHE_STORE', 'queue.default' => 'QUEUE_CONNECTION'] as $key => $variable) {
                if ($config->get($key) !== 'redis') {
                    $problems[] = "{$variable} must be \"redis\".";
                }
            }
        }

        // CORS is closed by default; an explicit list of origins may be set,
        // never a wildcard, since responses allow credentials (ADR-006).
        foreach ((array) $config->get('cors.allowed_origins') as $origin) {
            if (! is_string($origin) || preg_match('~^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$~D', $origin) !== 1) {
                $problems[] = 'CORS_ALLOWED_ORIGINS must list exact origins (scheme://host[:port]); wildcards are not allowed.';
                break;
            }
        }
        if ((array) $config->get('cors.allowed_origins_patterns') !== []) {
            $problems[] = 'CORS origin patterns are not allowed.';
        }

        // Trusted proxies (Phase 21/25): X-Forwarded-* is honored only from
        // listed addresses. Trusting every address would let any client spoof
        // its IP (and escape the IP-keyed rate limits), scheme and host.
        $proxies = (array) $config->get('codedna.trusted_proxies');
        if ($proxies === []) {
            $problems[] = 'TRUSTED_PROXIES must list the reverse proxy addresses.';
        }
        foreach ($proxies as $proxy) {
            if (! is_string($proxy) || in_array($proxy, ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0'], true) || preg_match('#^[0-9A-Fa-f:.]+(/[0-9]{1,3})?$#', $proxy) !== 1) {
                $problems[] = 'TRUSTED_PROXIES must list explicit IP addresses or CIDR ranges; wildcards are not allowed.';
                break;
            }
        }

        if (self::isDeployed($environment)) {
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

            $problems = [...$problems, ...$this->deployedProblems($config)];
        }

        return $problems;
    }

    /**
     * Production fail-closed checks (Phase 25,
     * docs/operations/production-configuration.md): what a deployment must
     * never run with, beyond the Phase 21 checks above. Messages name the
     * variable, never its value.
     *
     * @return list<string>
     */
    private function deployedProblems(Repository $config): array
    {
        $problems = [];

        // Database: explicit credentials, and a valid TLS mode (a managed
        // database outside the private network needs "require" or stronger).
        $database = (array) $config->get('database.connections.pgsql', []);
        foreach (['host' => 'DB_HOST', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $variable) {
            if (! is_string($database[$key] ?? null) || $database[$key] === '') {
                $problems[] = "{$variable} must be set in production.";
            }
        }
        if (! in_array($database['sslmode'] ?? null, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            $problems[] = 'DB_SSLMODE must be one of: disable, allow, prefer, require, verify-ca, verify-full.';
        }
        // A database outside the private network (Phase 27: any host that is
        // not an internal service name) is reached over TLS only.
        if (is_string($database['host'] ?? null) && self::isExternalHost($database['host'])
            && ! in_array($database['sslmode'] ?? null, ['require', 'verify-ca', 'verify-full'], true)) {
            $problems[] = 'DB_SSLMODE must be "require", "verify-ca" or "verify-full" for a database outside the private network (DB_HOST is not an internal service name).';
        }

        // Redis holds sessions, cache, locks and queues: never unauthenticated.
        foreach (['default', 'cache'] as $connection) {
            $password = $config->get("database.redis.{$connection}.password");
            $url = (string) $config->get("database.redis.{$connection}.url");
            if ((! is_string($password) || $password === '') && preg_match('#^rediss?://[^@/]*:[^@/]+@#', $url) !== 1) {
                $problems[] = 'REDIS_PASSWORD must be set in production.';
                break;
            }
        }
        // And outside the private network, only over TLS (Phase 27).
        if (! in_array($config->get('database.redis.default.scheme'), [null, 'tcp', 'tls'], true)) {
            $problems[] = 'REDIS_SCHEME must be empty, "tcp" or "tls".';
        }
        $redisHost = $config->get('database.redis.default.host');
        if (is_string($redisHost) && self::isExternalHost($redisHost) && $config->get('database.redis.default.scheme') !== 'tls') {
            $problems[] = 'REDIS_SCHEME must be "tls" for a Redis outside the private network (REDIS_HOST is not an internal service name).';
        }

        // Session cookie: HttpOnly, a SameSite policy, and a bounded lifetime.
        if ($config->get('session.http_only') !== true) {
            $problems[] = 'The session cookie must be HttpOnly.';
        }
        if (! in_array($config->get('session.same_site'), ['lax', 'strict'], true)) {
            $problems[] = 'SESSION_SAME_SITE must be "lax" or "strict" in production.';
        }
        $lifetime = $config->get('session.lifetime');
        if (! is_int($lifetime) || $lifetime < 5 || $lifetime > 1440) {
            $problems[] = 'SESSION_LIFETIME must be between 5 and 1440 minutes in production.';
        }

        // CORS origins, when any are listed, are https origins.
        foreach ((array) $config->get('cors.allowed_origins') as $origin) {
            if (is_string($origin) && ! str_starts_with($origin, 'https://')) {
                $problems[] = 'CORS_ALLOWED_ORIGINS must list https:// origins only in production.';
                break;
            }
        }

        // Debug-level logs may carry request details; production logs at info or above.
        $default = (string) $config->get('logging.default');
        $channels = $config->get("logging.channels.{$default}.driver") === 'stack' ? (array) $config->get("logging.channels.{$default}.channels") : [$default];
        foreach ($channels as $channel) {
            if ($config->get("logging.channels.{$channel}.level") === 'debug') {
                $problems[] = 'LOG_LEVEL must not be "debug" in production.';
                break;
            }
        }

        // Object storage: credentials, a bucket and a TLS endpoint. Plain
        // http is accepted only for an internal service name (no dot, e.g. the
        // private "minio" container), never for a public host.
        $storage = (array) $config->get('filesystems.disks.'.$config->get('codedna.sources.disk'), []);
        foreach (['key' => 'SOURCE_STORAGE_ACCESS_KEY_ID', 'secret' => 'SOURCE_STORAGE_SECRET_ACCESS_KEY', 'bucket' => 'SOURCE_STORAGE_BUCKET'] as $key => $variable) {
            if (! is_string($storage[$key] ?? null) || $storage[$key] === '') {
                $problems[] = "{$variable} must be set in production.";
            }
        }
        $endpoint = $storage['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $host = parse_url($endpoint, PHP_URL_HOST);
            $scheme = parse_url($endpoint, PHP_URL_SCHEME);
            if (! is_string($host) || ! in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && str_contains($host, '.'))) {
                $problems[] = 'SOURCE_STORAGE_ENDPOINT must use https:// (plain http only for an internal service name).';
            }
            if (parse_url($endpoint, PHP_URL_USER) !== null) {
                $problems[] = 'SOURCE_STORAGE_ENDPOINT must not contain credentials.';
            }
        }

        // Coding challenges execute untrusted code: in production only in an
        // evaluator that attests a gVisor sandbox (fail closed).
        if ($config->get('codedna.challenges.evaluator') === 'spool' && $config->get('codedna.challenges.required_isolation') !== 'gvisor') {
            $problems[] = 'CHALLENGE_EVALUATOR_ISOLATION must be "gvisor" in production (or set CHALLENGE_EVALUATOR=none).';
        }

        return $problems;
    }

    /**
     * Self-hosted installations (Phase 27, docs/enterprise/configuration-reference.md),
     * in every environment: the registration mode, the license file and the
     * trusted license keys. A configured license that cannot be read is a
     * configuration error; a license that does not verify is not (the
     * installation simply stays on the Community edition, see EnterpriseEdition).
     *
     * @return list<string>
     */
    private function selfHostedProblems(Repository $config): array
    {
        $problems = [];
        $mode = $config->get('codedna.registration.mode');
        $domains = (array) $config->get('codedna.registration.allowed_email_domains');
        if (! in_array($mode, ['open', 'restricted', 'closed'], true)) {
            $problems[] = 'REGISTRATION_MODE must be one of: open, restricted, closed.';
        } elseif ($mode === 'restricted' && $domains === []) {
            $problems[] = 'REGISTRATION_ALLOWED_EMAIL_DOMAINS must list at least one domain when REGISTRATION_MODE is "restricted".';
        }
        foreach ($domains as $domain) {
            if (! is_string($domain) || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
                $problems[] = 'REGISTRATION_ALLOWED_EMAIL_DOMAINS must list domain names (e.g. example.com), separated by commas.';
                break;
            }
        }
        if ($domains !== [] && $mode !== 'restricted') {
            $problems[] = 'REGISTRATION_ALLOWED_EMAIL_DOMAINS is set but REGISTRATION_MODE is not "restricted"; it would have no effect.';
        }

        $path = $config->get('codedna.enterprise.license_path');
        if (is_string($path) && $path !== '') {
            if (! str_starts_with($path, '/')) {
                $problems[] = 'CODEDNA_LICENSE_PATH must be an absolute path.';
            } elseif (! file_exists($path) || is_dir($path) || ! is_readable($path)) {
                $problems[] = 'CODEDNA_LICENSE_PATH does not name a readable file.';
            }
        }

        foreach ((array) $config->get('license.trusted_keys') as $id => $key) {
            $bytes = is_string($key) ? base64_decode($key, true) : false;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $id) !== 1 || ! is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $problems[] = 'config/license.php must map key ids to base64 Ed25519 public keys (32 bytes).';
                break;
            }
        }

        return $problems;
    }

    /**
     * A host outside the private network: anything but an internal service
     * name (a single label such as "postgres" or "redis"). Addresses and
     * qualified names count as external.
     */
    private static function isExternalHost(string $host): bool
    {
        return $host !== '' && (str_contains($host, '.') || str_contains($host, ':'));
    }

    /**
     * GitHub integration (Phase 19): off when no credential is set; when any
     * is set, all must be, with a usable private key, absolute URLs (HTTPS in
     * production) and download origins without paths.
     *
     * @return list<string>
     */
    private function githubProblems(Repository $config, string $environment): array
    {
        $settings = GitHubSettings::fromConfig($config);
        $credentials = [
            'GITHUB_APP_ID' => $settings->appId,
            'GITHUB_APP_SLUG' => $settings->appSlug,
            'GITHUB_APP_CLIENT_ID' => $settings->clientId,
            'GITHUB_APP_CLIENT_SECRET' => $settings->clientSecret,
            'GITHUB_APP_PRIVATE_KEY or GITHUB_APP_PRIVATE_KEY_PATH' => $settings->privateKey,
        ];
        $set = array_filter($credentials, static fn (string $value): bool => $value !== '');
        if ($set === []) {
            return [];
        }
        if (count($set) !== count($credentials)) {
            return ['GitHub App configuration is incomplete: set '.implode(', ', array_keys(array_diff_key($credentials, $set))).'.'];
        }

        $problems = [];
        if (openssl_pkey_get_private($settings->privateKey) === false) {
            $problems[] = 'GITHUB_APP_PRIVATE_KEY is not a readable PEM private key.';
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $settings->appSlug) !== 1) {
            $problems[] = "GITHUB_APP_SLUG must be the GitHub App's URL slug.";
        }
        $https = self::isDeployed($environment);
        foreach (['GITHUB_API_URL' => $settings->apiUrl, 'GITHUB_WEB_URL' => $settings->webUrl, 'GITHUB_CALLBACK_URL' => $settings->callbackUrl] as $name => $url) {
            if (! self::absoluteUrl($url, $https)) {
                $problems[] = "{$name} must be an absolute ".($https ? 'https' : 'http(s)').' URL.';
            }
        }
        if ($settings->archiveOrigins === []) {
            $problems[] = 'GITHUB_ARCHIVE_ORIGINS must list at least one origin.';
        }
        foreach ($settings->archiveOrigins as $origin) {
            $path = parse_url($origin, PHP_URL_PATH);
            if (! self::absoluteUrl($origin, $https) || ($path !== null && $path !== '') || parse_url($origin, PHP_URL_QUERY) !== null) {
                $problems[] = 'GITHUB_ARCHIVE_ORIGINS entries must be origins (scheme://host[:port]) without a path'.($https ? ', using https' : '').'.';
                break;
            }
        }
        $job = (int) $config->get('codedna.github.job_timeout_seconds');
        if ($settings->timeoutSeconds < 1 || $settings->connectTimeoutSeconds < 1 || $settings->downloadTimeoutSeconds < 1
            || $job <= $settings->downloadTimeoutSeconds + $settings->timeoutSeconds) {
            $problems[] = 'GitHub timeouts must be positive and GITHUB_IMPORT_JOB_TIMEOUT_SECONDS must exceed the download and request timeouts.';
        }
        $retryAfter = (int) $config->get('queue.connections.'.$config->get('codedna.github.queue_connection').'.retry_after');
        if ($retryAfter <= $job) {
            $problems[] = 'GITHUB_IMPORT_JOB_TIMEOUT_SECONDS must be lower than the queue retry_after.';
        }

        return $problems;
    }

    private static function absoluteUrl(string $url, bool $httpsOnly): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $httpsOnly ? $parts['scheme'] === 'https' : in_array($parts['scheme'], ['http', 'https'], true);
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

        $slots = $analyzer['max_concurrency'] ?? null;
        if (! is_int($slots) || $slots < 1 || $slots > 64) {
            $problems[] = 'ANALYZER_MAX_CONCURRENCY must be between 1 and 64.';
        }
        $wait = $analyzer['slot_wait_seconds'] ?? null;
        if (! is_int($wait) || $wait < 1) {
            $problems[] = 'ANALYZER_SLOT_WAIT_SECONDS must be a positive integer.';
        } elseif (is_int($chain['ANALYZER_TIMEOUT_SECONDS']) && is_int($chain['ANALYSIS_JOB_TIMEOUT_SECONDS'])
            && $chain['ANALYSIS_JOB_TIMEOUT_SECONDS'] <= $chain['ANALYZER_TIMEOUT_SECONDS'] + $wait) {
            $problems[] = 'ANALYZER_TIMEOUT_SECONDS plus ANALYZER_SLOT_WAIT_SECONDS must be lower than ANALYSIS_JOB_TIMEOUT_SECONDS.';
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
     * AI assessment (Phase 15). Checked whether or not AI is enabled, except
     * the provider settings an enabled provider needs.
     *
     * @return list<string>
     */
    private function aiProblems(Repository $config, string $environment): array
    {
        $problems = [];
        $ai = (array) $config->get('codedna.ai', []);

        if (! in_array($ai['version'] ?? null, AssessmentSpecification::VERSIONS, true)) {
            $problems[] = 'CODEDNA_ASSESSMENT_VERSION must be one of: '.implode(', ', AssessmentSpecification::VERSIONS).'.';
        }
        if (! is_bool($ai['enabled'] ?? null)) {
            $problems[] = 'AI_ENABLED must be true or false.';
        }
        $provider = $ai['provider'] ?? null;
        if (! in_array($provider, [OpenAiCompatibleProvider::NAME, FakeAiProvider::NAME], true)) {
            $problems[] = 'AI_PROVIDER must be "openai_compatible" or "fake".';
        }
        if ($provider === FakeAiProvider::NAME && self::isDeployed($environment)) {
            $problems[] = 'AI_PROVIDER "fake" is not allowed in production.';
        }
        if (! in_array($ai['structured_output'] ?? null, OpenAiCompatibleProvider::STRUCTURED_OUTPUT_MODES, true)) {
            $problems[] = 'AI_STRUCTURED_OUTPUT must be one of: '.implode(', ', OpenAiCompatibleProvider::STRUCTURED_OUTPUT_MODES).'.';
        }

        if (($ai['enabled'] ?? false) === true && $provider === OpenAiCompatibleProvider::NAME) {
            if (! is_string($ai['model'] ?? null) || preg_match('#^[A-Za-z0-9._:/-]{1,128}$#', $ai['model']) !== 1) {
                $problems[] = 'AI_MODEL must be set to a model identifier when AI is enabled.';
            }
            $url = $ai['base_url'] ?? null;
            $scheme = self::isDeployed($environment) ? 'https' : 'https?';
            if (! is_string($url) || preg_match('#^'.$scheme.'://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~-]+)*$#', $url) !== 1) {
                $problems[] = self::isDeployed($environment)
                    ? 'AI_BASE_URL must be an https:// URL without query or credentials in production.'
                    : 'AI_BASE_URL must be an http(s):// URL without query or credentials.';
            }
        }

        $ranges = [
            'AI_CONNECT_TIMEOUT_SECONDS' => [$ai['connect_timeout_seconds'] ?? null, 1, 60],
            'AI_TIMEOUT_SECONDS' => [$ai['timeout_seconds'] ?? null, 1, 600],
            'AI_MAX_INPUT_BYTES' => [$ai['max_input_bytes'] ?? null, 1024, 262144],
            'AI_MAX_OUTPUT_BYTES' => [$ai['max_output_bytes'] ?? null, 1024, 262144],
            'AI_MAX_OUTPUT_TOKENS' => [$ai['max_output_tokens'] ?? null, 256, 32768],
            'AI_MAX_ATTEMPTS' => [$ai['max_attempts'] ?? null, 1, 5],
            'AI_STALE_AFTER_SECONDS' => [$ai['stale_after_seconds'] ?? null, 60, 86400],
            'AI_QUEUED_STALE_AFTER_SECONDS' => [$ai['queued_stale_after_seconds'] ?? null, 60, 604800],
        ];
        foreach ($ranges as $variable => [$value, $min, $max]) {
            if (! is_int($value) || $value < $min || $value > $max) {
                $problems[] = "{$variable} must be an integer between {$min} and {$max}.";
            }
        }

        // The provider call must time out before the worker kills the job,
        // and the job before Redis hands it to another worker.
        $timeout = $ai['timeout_seconds'] ?? null;
        $job = $ai['job_timeout_seconds'] ?? null;
        $retryAfter = $config->get('queue.connections.'.($ai['queue_connection'] ?? 'analysis').'.retry_after');
        if (! is_int($job) || ! is_int($timeout) || $job <= $timeout) {
            $problems[] = 'AI_JOB_TIMEOUT_SECONDS must be greater than AI_TIMEOUT_SECONDS.';
        } elseif (! is_int($retryAfter) || $job >= $retryAfter) {
            $problems[] = 'AI_JOB_TIMEOUT_SECONDS must be lower than the queue retry_after.';
        }

        return $problems;
    }

    /**
     * Coding challenges (Phase 16).
     *
     * @return list<string>
     */
    /**
     * Billing (Phase 23): only known providers; the fake provider never in a
     * deployed environment; a configured provider needs a strong webhook secret.
     *
     * @return list<string>
     */
    private function billingProblems(Repository $config, string $environment): array
    {
        $problems = [];
        $billing = (array) $config->get('codedna.billing', []);
        $provider = $billing['provider'] ?? null;
        if (! in_array($provider, ['none', FakePaymentProvider::NAME], true)) {
            $problems[] = 'BILLING_PROVIDER must be "none" or "fake".';
        }
        if ($provider === FakePaymentProvider::NAME && self::isDeployed($environment)) {
            $problems[] = 'BILLING_PROVIDER "fake" is not allowed in production.';
        }
        if ($provider !== 'none' && strlen((string) ($billing['webhook_secret'] ?? '')) < 32) {
            $problems[] = 'BILLING_WEBHOOK_SECRET must be at least 32 characters when a billing provider is configured.';
        }

        return $problems;
    }

    private function challengeProblems(Repository $config): array
    {
        $problems = [];
        $challenges = (array) $config->get('codedna.challenges', []);

        if (! is_bool($challenges['enabled'] ?? null)) {
            $problems[] = 'CHALLENGE_ENABLED must be true or false.';
        }
        if (! in_array($challenges['catalog_version'] ?? null, ChallengeCatalog::VERSIONS, true)) {
            $problems[] = 'CODEDNA_CHALLENGE_CATALOG_VERSION must be one of: '.implode(', ', ChallengeCatalog::VERSIONS).'.';
        }
        if (! in_array($challenges['evaluator'] ?? null, ['spool', 'none'], true)) {
            $problems[] = 'CHALLENGE_EVALUATOR must be "spool" or "none".';
        }
        if (! in_array($challenges['required_isolation'] ?? null, SpoolChallengeEvaluator::ISOLATION_LEVELS, true)) {
            $problems[] = 'CHALLENGE_EVALUATOR_ISOLATION must be one of: '.implode(', ', SpoolChallengeEvaluator::ISOLATION_LEVELS).'.';
        }
        $spool = $challenges['spool_path'] ?? null;
        if (! is_string($spool) || preg_match('#^/[A-Za-z0-9._/-]+$#', $spool) !== 1 || str_contains($spool, '..')) {
            $problems[] = 'CHALLENGE_EVALUATOR_SPOOL must be an absolute path.';
        }
        $ranges = [
            'CHALLENGE_MAX_SOURCE_BYTES' => [$challenges['max_source_bytes'] ?? null, 256, 65536],
            'CHALLENGE_MAX_SOURCE_LINES' => [$challenges['max_source_lines'] ?? null, 10, 5000],
            'CHALLENGE_MAX_ATTEMPTS' => [$challenges['max_attempts'] ?? null, 1, 20],
            'CHALLENGE_EVALUATOR_WAIT_SECONDS' => [$challenges['wait_seconds'] ?? null, 5, 300],
        ];
        foreach ($ranges as $variable => [$value, $min, $max]) {
            if (! is_int($value) || $value < $min || $value > $max) {
                $problems[] = "{$variable} must be an integer between {$min} and {$max}.";
            }
        }
        $wait = $challenges['wait_seconds'] ?? null;
        $job = $challenges['job_timeout_seconds'] ?? null;
        $retryAfter = $config->get('queue.connections.'.($challenges['queue_connection'] ?? 'analysis').'.retry_after');
        if (! is_int($job) || ! is_int($wait) || $job <= $wait) {
            $problems[] = 'CHALLENGE_JOB_TIMEOUT_SECONDS must be greater than CHALLENGE_EVALUATOR_WAIT_SECONDS.';
        } elseif (! is_int($retryAfter) || $job >= $retryAfter) {
            $problems[] = 'CHALLENGE_JOB_TIMEOUT_SECONDS must be lower than the queue retry_after.';
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
