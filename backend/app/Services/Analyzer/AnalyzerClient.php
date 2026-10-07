<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use App\Enums\AnalysisFailure;
use App\Models\AnalysisRun;
use App\Models\SourceSnapshot;
use App\Support\AnalysisVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The only place Laravel talks to the analyzer (internal analyzer contract,
 * docs/api/internal-analyzer-contract.md). One call = one attempt:
 *
 * 1. a fresh, short-lived pre-signed GET URL for the run's snapshot,
 *    generated now and never stored, logged or queued;
 * 2. the request body (validated against the published request schema),
 *    signed with HMAC-SHA256 over the frozen canonical string;
 * 3. POST /internal/v1/analyze to the configured analyzer URL (never one
 *    from user input), with connect and total timeouts, no redirects;
 * 4. the response is trusted only after: its HMAC signature verifies; it
 *    decodes as JSON; it validates against the published schema of the
 *    requested result type; analysis_run_id, request_id, result_type and
 *    contract major match the request; and result_hash equals the SHA-256
 *    of the canonical JSON recomputed here.
 *
 * Every failure becomes an AnalyzerException with a failure code and a
 * retry decision (docs/architecture/data-flow.md#retry-matrix). Nothing
 * from a response body, no URL and no secret ends up in an exception message.
 */
final class AnalyzerClient
{
    public const PATH = '/internal/v1/analyze';

    public const CONTRACT_VERSION = '1.0';

    /** Sections excluded from result_hash (internal contract, section 4). */
    private const UNHASHED_FIELDS = ['request_id', 'diagnostics', 'result_hash'];

    private const SCHEMAS = [
        'request' => 'analyze-request.schema.json',
        'error' => 'error.schema.json',
        'foundation' => 'foundation-result.schema.json',
        'static_analysis' => 'static-analysis-result.schema.json',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    public function __construct(
        private readonly Repository $config,
        private readonly Http $http,
        private readonly Filesystems $filesystems,
        private readonly JsonSchemaValidator $validator,
    ) {}

    /**
     * @throws AnalyzerException
     */
    public function analyze(AnalysisRun $run, SourceSnapshot $snapshot, int $attempt, string $requestId): AnalyzerResult
    {
        $body = $this->requestBody($run, $snapshot, $attempt);
        $signer = $this->signer();
        $headers = $signer->signRequest(Carbon::now()->getTimestamp(), 'POST', self::PATH, $requestId, $body) + [
            'Idempotency-Key' => $run->id,
            'Accept' => 'application/json',
        ];

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->connectTimeout((int) $this->config->get('codedna.analyzer.connect_timeout_seconds'))
                ->timeout((int) $this->config->get('codedna.analyzer.timeout_seconds'))
                ->withoutRedirecting()
                ->post($this->config->get('codedna.analyzer.url').self::PATH);
        } catch (ConnectionException $e) {
            // cURL error 28: the connect or total timeout fired.
            if (str_contains($e->getMessage(), 'cURL error 28')) {
                throw AnalyzerException::retryable(AnalysisFailure::AnalyzerTimeout, 'transport_timeout');
            }
            throw AnalyzerException::retryable(AnalysisFailure::AnalyzerUnavailable, 'transport_error');
        }

        return $this->verify($response, $run, $snapshot, $requestId, $signer);
    }

    private function requestBody(AnalysisRun $run, SourceSnapshot $snapshot, int $attempt): string
    {
        $body = [
            'contract_version' => self::CONTRACT_VERSION,
            'analysis_run_id' => $run->id,
            'attempt' => $attempt,
            'source' => [
                'type' => 'archive',
                'format' => 'zip',
                'url' => $this->sourceUrl($snapshot),
                'sha256' => $snapshot->source_hash,
                'size_bytes' => $snapshot->size_bytes,
            ],
            'options' => ['result_type' => $run->result_type->value],
        ];

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $errors = $this->validator->validate(json_decode($encoded, false, 512, JSON_THROW_ON_ERROR), $this->schema('request'));
        if ($errors !== []) {
            // A bug in this client, not a property of the source.
            throw AnalyzerException::permanent(AnalysisFailure::AnalysisFailed, 'request_schema_violation');
        }

        return $encoded;
    }

    /**
     * A pre-signed GET URL generated for this attempt only (ADR-003). It is
     * a bearer credential: it lives in this request body and nowhere else.
     */
    private function sourceUrl(SourceSnapshot $snapshot): string
    {
        $disk = (string) $this->config->get('codedna.sources.disk');
        if ($snapshot->storage_disk !== $disk) {
            throw AnalyzerException::permanent(AnalysisFailure::SourceUnavailable, 'unsupported_storage_disk');
        }

        try {
            $ttl = (int) $this->config->get('codedna.analyzer.source_url_ttl_seconds');

            return $this->filesystems->disk($disk)->temporaryUrl($snapshot->storage_key, Carbon::now()->addSeconds($ttl));
        } catch (Throwable) {
            throw AnalyzerException::retryable(AnalysisFailure::SourceUnavailable, 'presign_failed');
        }
    }

    private function verify(Response $response, AnalysisRun $run, SourceSnapshot $snapshot, string $requestId, HmacSigner $signer): AnalyzerResult
    {
        $status = $response->status();
        $raw = $response->body();
        $timestamp = $response->header(HmacSigner::HEADER_TIMESTAMP) ?: null;
        $signature = $response->header(HmacSigner::HEADER_SIGNATURE) ?: null;

        if ($timestamp === null && $signature === null) {
            // Unsigned: an error before authentication, or not the analyzer at all.
            throw match (true) {
                $status === 401 => AnalyzerException::permanent(AnalysisFailure::AnalyzerAuthFailed, 'request_rejected', $status),
                in_array($status, [429, 502, 503, 504], true) => AnalyzerException::retryable(
                    AnalysisFailure::AnalyzerUnavailable, 'unsigned_transient_status', $status, null, $this->retryAfter($response),
                ),
                default => AnalyzerException::permanent(AnalysisFailure::AnalyzerInvalidResponse, 'unsigned_response', $status),
            };
        }

        $now = Carbon::now()->getTimestamp();
        if (! $signer->verifyResponse($timestamp, $signature, $status, self::PATH, $requestId, $raw, $now)) {
            // Never retried: an unauthenticated result is a security failure, not a transient one.
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerAuthFailed, 'response_signature_invalid', $status);
        }

        $decoded = $this->decode($raw, $status);

        if ($status !== 200) {
            $this->assertValid($decoded, 'error', AnalysisFailure::AnalyzerInvalidResponse, $status);
            $code = (string) $decoded->error->code;
            [$failure, $retryable] = AnalyzerErrorMap::map($code);

            throw new AnalyzerException($failure, $retryable, $status, $code, $retryable ? $this->retryAfter($response) : null, 'analyzer_error');
        }

        $type = $run->result_type;
        $this->assertValid($decoded, $type->value, AnalysisFailure::AnalyzerResultInvalid, $status);

        if ($decoded->result_type !== $type->value) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerResultInvalid, 'result_type_mismatch', $status);
        }
        if (strtolower((string) $decoded->analysis_run_id) !== strtolower($run->id)) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerResultInvalid, 'run_id_mismatch', $status);
        }
        if ($decoded->request_id !== $requestId) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerResultInvalid, 'request_id_mismatch', $status);
        }
        // The result must describe exactly the bytes of this snapshot (Phase
        // 21): a correctly signed result computed over other bytes, from an
        // analyzer bug or a stale cache entry, is never stored or scored.
        if (! hash_equals($snapshot->source_hash, (string) $decoded->source->sha256)
            || (int) $decoded->source->size_bytes !== $snapshot->size_bytes) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerResultInvalid, 'source_mismatch', $status);
        }
        if (explode('.', (string) $decoded->contract_version)[0] !== explode('.', self::CONTRACT_VERSION)[0]) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerResultInvalid, 'contract_major_mismatch', $status);
        }

        $hashed = new stdClass;
        foreach (get_object_vars($decoded) as $key => $value) {
            if (! in_array($key, self::UNHASHED_FIELDS, true)) {
                $hashed->{$key} = $value;
            }
        }
        if (! hash_equals(CanonicalJson::hash($hashed), (string) $decoded->result_hash)) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerResultHashMismatch, 'result_hash_mismatch', $status);
        }

        $versions = $decoded->versions;

        return new AnalyzerResult(
            resultType: $type,
            resultHash: (string) $decoded->result_hash,
            versions: new AnalysisVersions(
                analyzer: (string) $versions->analyzer,
                ir: (string) $versions->ir,
                metrics: is_string($versions->metrics) ? $versions->metrics : null,
                scoring: null,
                contract: (string) $decoded->contract_version,
            ),
            result: $decoded,
            rawBody: $raw,
            sizeBytes: strlen($raw),
            replayed: $response->header('Idempotent-Replayed') === 'true',
        );
    }

    private function decode(string $raw, int $status): stdClass
    {
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerInvalidResponse, 'malformed_json', $status);
        }
        if (! $decoded instanceof stdClass) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerInvalidResponse, 'not_an_object', $status);
        }

        return $decoded;
    }

    private function assertValid(stdClass $decoded, string $schema, AnalysisFailure $failure, int $status): void
    {
        if ($this->validator->validate($decoded, $this->schema($schema)) !== []) {
            throw AnalyzerException::permanent($failure, 'schema_violation', $status);
        }
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return preg_match('/^[0-9]{1,5}$/', $value) === 1 ? (int) $value : null;
    }

    private function signer(): HmacSigner
    {
        $secrets = array_values(array_filter([
            (string) $this->config->get('codedna.analyzer.hmac_secret'),
            (string) $this->config->get('codedna.analyzer.hmac_secret_previous'),
        ], static fn (string $secret): bool => $secret !== ''));

        if ($secrets === []) {
            throw AnalyzerException::permanent(AnalysisFailure::AnalyzerAuthFailed, 'secret_not_configured');
        }

        return new HmacSigner($secrets, (int) $this->config->get('codedna.analyzer.max_skew_seconds'));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $name): array
    {
        if (! isset($this->schemas[$name])) {
            $path = rtrim((string) $this->config->get('codedna.analyzer.contracts_path'), '/').'/'.self::SCHEMAS[$name];
            $contents = is_readable($path) ? file_get_contents($path) : false;
            if ($contents === false) {
                throw new RuntimeException('Analyzer contract schema not readable: '.self::SCHEMAS[$name]);
            }
            $this->schemas[$name] = (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->schemas[$name];
    }
}
