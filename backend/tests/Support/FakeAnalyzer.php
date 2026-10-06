<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Analyzer\AnalyzerClient;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Analyzer\HmacSigner;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * Simulates the analyzer for Http::fake(): answers like the real service,
 * from real analyzer responses captured in tests/Fixtures/analyzer, with
 * the run ID, request ID and result type of the incoming request, a
 * recomputed result_hash and a valid response signature. Tests break one
 * property at a time through $mutate.
 */
final class FakeAnalyzer
{
    public const SECRET = 'tttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt';

    /** @var list<array{headers: array<string, string>, body: array<string, mixed>}> */
    public static array $requests = [];

    public static function configure(): void
    {
        config([
            'codedna.analyzer.hmac_secret' => self::SECRET,
            'codedna.analyzer.hmac_secret_previous' => '',
            'codedna.analyzer.url' => 'http://analyzer:8000',
        ]);
        self::$requests = [];
    }

    public static function fixture(string $resultType): stdClass
    {
        $file = $resultType === 'static_analysis' ? 'static-analysis-response.json' : 'foundation-response.json';

        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/analyzer/'.$file), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A successful, correctly signed result for the request.
     *
     * @param  (callable(stdClass): void)|null  $mutate  changes the result before it is hashed
     * @param  (callable(stdClass): void)|null  $afterHash  changes the result after hashing
     */
    public static function success(Request $request, ?callable $mutate = null, ?callable $afterHash = null): PromiseInterface
    {
        $sent = self::record($request);
        $result = self::fixture((string) ($sent['options']['result_type'] ?? 'foundation'));
        $result->analysis_run_id = $sent['analysis_run_id'];
        $result->request_id = $request->header('X-Request-ID')[0];
        if ($mutate !== null) {
            $mutate($result);
        }
        $hashed = clone $result;
        unset($hashed->request_id, $hashed->diagnostics, $hashed->result_hash);
        $result->result_hash = CanonicalJson::hash($hashed);
        if ($afterHash !== null) {
            $afterHash($result);
        }

        return self::signed($request, 200, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * A signed analyzer error envelope (internal contract, section 5).
     *
     * @param  array<string, string>  $headers
     */
    public static function error(Request $request, int $status, string $code, bool $retryable, array $headers = []): PromiseInterface
    {
        self::record($request);
        $body = json_encode(['error' => [
            'code' => $code,
            'message' => 'Fixed message.',
            'retryable' => $retryable,
            'request_id' => $request->header('X-Request-ID')[0],
            'details' => (object) [],
        ]], JSON_THROW_ON_ERROR);

        return self::signed($request, $status, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function signed(Request $request, int $status, string $body, array $headers = [], ?string $secret = null): PromiseInterface
    {
        $timestamp = (string) Carbon::now()->getTimestamp();
        $canonical = HmacSigner::canonicalResponse($timestamp, $status, AnalyzerClient::PATH, $request->header('X-Request-ID')[0], $body);

        return Http::response($body, $status, $headers + [
            'Content-Type' => 'application/json',
            'X-CodeDNA-Timestamp' => $timestamp,
            'X-CodeDNA-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret ?? self::SECRET),
        ]);
    }

    /**
     * @return array<string, mixed> the decoded request body
     */
    public static function record(Request $request): array
    {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        self::$requests[] = ['headers' => array_map(static fn (array $values): string => (string) $values[0], $request->headers()), 'body' => $body];

        return $body;
    }
}
