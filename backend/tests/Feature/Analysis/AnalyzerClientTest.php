<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisFailure;
use App\Enums\AnalysisResultType;
use App\Models\AnalysisRun;
use App\Models\SourceSnapshot;
use App\Services\Analyzer\AnalyzerClient;
use App\Services\Analyzer\AnalyzerException;
use App\Services\Analyzer\HmacSigner;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\FakeAnalyzer;
use Tests\TestCase;

/**
 * AnalyzerClient against a simulated analyzer: request signing and content,
 * and every verification step on the way back (internal analyzer contract).
 */
final class AnalyzerClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAnalyzer::configure();
    }

    private function makeRun(AnalysisResultType $type = AnalysisResultType::StaticAnalysis): AnalysisRun
    {
        return AnalysisRun::factory()->running()->create(['result_type' => $type]);
    }

    /**
     * @param  Closure(Request): PromiseInterface  $handler
     */
    private function analyzeWith(Closure $handler, ?AnalysisRun $run = null): mixed
    {
        Http::fake(['http://analyzer:8000/*' => $handler]);
        $run ??= $this->makeRun();

        return app(AnalyzerClient::class)->analyze($run, SourceSnapshot::query()->findOrFail($run->source_snapshot_id), 1, (string) Str::uuid());
    }

    private function failure(Closure $handler, ?AnalysisRun $run = null): AnalyzerException
    {
        try {
            $this->analyzeWith($handler, $run);
        } catch (AnalyzerException $e) {
            return $e;
        }
        $this->fail('Expected an AnalyzerException');
    }

    public function test_a_signed_verified_result_is_returned_for_both_result_types(): void
    {
        foreach (AnalysisResultType::cases() as $type) {
            $run = $this->makeRun($type);
            $result = $this->analyzeWith(fn (Request $r) => FakeAnalyzer::success($r), $run);

            $this->assertSame($type, $result->resultType);
            $this->assertSame($type->value, $result->result->result_type);
            $this->assertSame($run->id, $result->result->analysis_run_id);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->resultHash);
            $this->assertSame($type === AnalysisResultType::Foundation ? '1.0' : '1.1', $result->versions->ir);
            $this->assertSame($type === AnalysisResultType::Foundation ? null : '1.0', $result->versions->metrics);
            $this->assertNull($result->versions->scoring);
        }
    }

    public function test_the_request_is_signed_and_carries_only_ids_hashes_and_a_fresh_presigned_url(): void
    {
        $run = $this->makeRun();
        $snapshot = SourceSnapshot::query()->findOrFail($run->source_snapshot_id);
        $this->analyzeWith(fn (Request $r) => FakeAnalyzer::success($r), $run);

        Http::assertSent(function (Request $request) use ($run, $snapshot): bool {
            $body = json_decode($request->body(), true);
            $this->assertSame('http://analyzer:8000/internal/v1/analyze', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame($run->id, $request->header('Idempotency-Key')[0]);
            $this->assertSame([
                'contract_version' => '1.0',
                'analysis_run_id' => $run->id,
                'attempt' => 1,
                'source' => [
                    'type' => 'archive', 'format' => 'zip', 'url' => $body['source']['url'],
                    'sha256' => $snapshot->source_hash, 'size_bytes' => $snapshot->size_bytes,
                ],
                'options' => ['result_type' => 'static_analysis'],
            ], $body);
            // A SigV4 pre-signed GET URL for exactly this snapshot's object.
            $this->assertStringContainsString($snapshot->storage_key, $body['source']['url']);
            $this->assertStringContainsString('X-Amz-Signature=', $body['source']['url']);
            // 900 s TTL (the SDK subtracts the elapsed second when it rounds).
            $this->assertMatchesRegularExpression('/[?&]X-Amz-Expires=(899|900)(&|$)/', $body['source']['url']);

            $canonical = HmacSigner::canonicalRequest((int) $request->header('X-CodeDNA-Timestamp')[0], 'POST', '/internal/v1/analyze', $request->header('X-Request-ID')[0], $request->body());
            $this->assertSame('v1='.hash_hmac('sha256', $canonical, FakeAnalyzer::SECRET), $request->header('X-CodeDNA-Signature')[0]);

            return true;
        });
    }

    /**
     * @return iterable<string, array{0: Closure(Request): PromiseInterface, 1: AnalysisFailure, 2: bool}>
     */
    public static function failures(): iterable
    {
        yield 'response signed with another secret' => [fn (Request $r) => FakeAnalyzer::signed($r, 200, '{}', [], str_repeat('x', 64)), AnalysisFailure::AnalyzerAuthFailed, false];
        yield 'signature over a different body' => [function (Request $r) {
            $response = FakeAnalyzer::success($r)->wait();

            return Http::response($response->getBody().' ', 200, $response->getHeaders());
        }, AnalysisFailure::AnalyzerAuthFailed, false];
        yield 'unsigned 200' => [fn () => Http::response('{}', 200), AnalysisFailure::AnalyzerInvalidResponse, false];
        yield 'unsigned 401 (our request was rejected)' => [fn () => Http::response('{"error":{}}', 401), AnalysisFailure::AnalyzerAuthFailed, false];
        yield 'unsigned 502' => [fn () => Http::response('Bad gateway', 502), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'unsigned 503' => [fn () => Http::response('', 503), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'unsigned 504' => [fn () => Http::response('', 504), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'unsigned 429' => [fn () => Http::response('', 429), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'unsigned 500' => [fn () => Http::response('oops', 500), AnalysisFailure::AnalyzerInvalidResponse, false];
        yield 'signed malformed JSON' => [fn (Request $r) => FakeAnalyzer::signed($r, 200, '{"result_type": '), AnalysisFailure::AnalyzerInvalidResponse, false];
        yield 'signed JSON array' => [fn (Request $r) => FakeAnalyzer::signed($r, 200, '[]'), AnalysisFailure::AnalyzerInvalidResponse, false];
        yield 'schema violation' => [fn (Request $r) => FakeAnalyzer::success($r, fn (stdClass $x) => $x->unexpected = 1), AnalysisFailure::AnalyzerResultInvalid, false];
        yield 'result of the other type' => [fn (Request $r) => FakeAnalyzer::signed($r, 200, (string) json_encode(FakeAnalyzer::fixture('foundation'))), AnalysisFailure::AnalyzerResultInvalid, false];
        yield 'wrong run ID' => [fn (Request $r) => FakeAnalyzer::success($r, fn (stdClass $x) => $x->analysis_run_id = strtolower((string) Str::ulid())), AnalysisFailure::AnalyzerResultInvalid, false];
        yield 'wrong request ID' => [fn (Request $r) => FakeAnalyzer::success($r, fn (stdClass $x) => $x->request_id = (string) Str::uuid()), AnalysisFailure::AnalyzerResultInvalid, false];
        yield 'other contract major' => [fn (Request $r) => FakeAnalyzer::success($r, fn (stdClass $x) => $x->contract_version = '2.0'), AnalysisFailure::AnalyzerResultInvalid, false];
        // Phase 21: correctly signed and hashed, but computed over other bytes.
        yield 'result for other source bytes' => [fn (Request $r) => FakeAnalyzer::success($r, fn (stdClass $x) => $x->source->sha256 = str_repeat('a', 64)), AnalysisFailure::AnalyzerResultInvalid, false];
        yield 'result for a source of another size' => [fn (Request $r) => FakeAnalyzer::success($r, fn (stdClass $x) => $x->source->size_bytes++), AnalysisFailure::AnalyzerResultInvalid, false];
        yield 'result_hash does not match the content' => [fn (Request $r) => FakeAnalyzer::success($r, null, fn (stdClass $x) => $x->source->files_total++), AnalysisFailure::AnalyzerResultHashMismatch, false];
        yield 'forged result_hash' => [fn (Request $r) => FakeAnalyzer::success($r, null, fn (stdClass $x) => $x->result_hash = str_repeat('0', 64)), AnalysisFailure::AnalyzerResultHashMismatch, false];
        yield 'analyzer INVALID_ARCHIVE' => [fn (Request $r) => FakeAnalyzer::error($r, 422, 'INVALID_ARCHIVE', false), AnalysisFailure::InvalidArchive, false];
        yield 'analyzer SOURCE_URL_EXPIRED' => [fn (Request $r) => FakeAnalyzer::error($r, 422, 'SOURCE_URL_EXPIRED', true), AnalysisFailure::SourceUrlExpired, true];
        yield 'analyzer ANALYZER_BUSY' => [fn (Request $r) => FakeAnalyzer::error($r, 503, 'ANALYZER_BUSY', true, ['Retry-After' => '7']), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'analyzer INTERNAL_ERROR' => [fn (Request $r) => FakeAnalyzer::error($r, 500, 'INTERNAL_ERROR', true), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'analyzer ANALYSIS_TIMEOUT (deterministic)' => [fn (Request $r) => FakeAnalyzer::error($r, 504, 'ANALYSIS_TIMEOUT', false), AnalysisFailure::AnalysisTimeout, false];
        yield 'analyzer error with a retryable flag it does not deserve' => [fn (Request $r) => FakeAnalyzer::error($r, 422, 'NO_SUPPORTED_FILES', true), AnalysisFailure::NoSupportedFiles, false];
        yield 'connection refused' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect to analyzer port 8000'), AnalysisFailure::AnalyzerUnavailable, true];
        yield 'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 300000 milliseconds'), AnalysisFailure::AnalyzerTimeout, true];
    }

    #[DataProvider('failures')]
    public function test_failures_are_classified(Closure $handler, AnalysisFailure $failure, bool $retryable): void
    {
        $e = $this->failure($handler);

        $this->assertSame($failure, $e->failure);
        $this->assertSame($retryable, $e->retryable);
        // Exception messages are fixed identifiers: never URLs, bodies or secrets.
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', $e->getMessage());
    }

    public function test_retry_after_from_a_busy_analyzer_is_kept(): void
    {
        $e = $this->failure(fn (Request $r) => FakeAnalyzer::error($r, 503, 'ANALYZER_BUSY', true, ['Retry-After' => '7']));
        $this->assertSame(7, $e->retryAfterSeconds);
    }

    public function test_a_response_signature_outside_the_freshness_window_is_rejected(): void
    {
        $e = $this->failure(function (Request $r) {
            $response = FakeAnalyzer::success($r)->wait();
            $stale = (string) (time() - 3600);
            $canonical = HmacSigner::canonicalResponse($stale, 200, '/internal/v1/analyze', $r->header('X-Request-ID')[0], (string) $response->getBody());

            return Http::response((string) $response->getBody(), 200, [
                'X-CodeDNA-Timestamp' => $stale,
                'X-CodeDNA-Signature' => 'v1='.hash_hmac('sha256', $canonical, FakeAnalyzer::SECRET),
            ]);
        });

        $this->assertSame(AnalysisFailure::AnalyzerAuthFailed, $e->failure);
    }

    public function test_redirects_are_not_followed(): void
    {
        $e = $this->failure(fn () => Http::response('', 302, ['Location' => 'http://169.254.169.254/']));

        $this->assertSame(AnalysisFailure::AnalyzerInvalidResponse, $e->failure);
        Http::assertSentCount(1);
    }

    public function test_the_analyzer_url_comes_only_from_configuration(): void
    {
        config(['codedna.analyzer.url' => 'http://analyzer-internal:9000']);
        Http::fake(['http://analyzer-internal:9000/*' => fn (Request $r) => FakeAnalyzer::success($r)]);
        $run = $this->makeRun();

        app(AnalyzerClient::class)->analyze($run, SourceSnapshot::query()->findOrFail($run->source_snapshot_id), 1, (string) Str::uuid());

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://analyzer-internal:9000/internal/v1/analyze');
    }

    public function test_a_missing_secret_fails_without_calling_the_analyzer(): void
    {
        config(['codedna.analyzer.hmac_secret' => '']);
        $e = $this->failure(fn (Request $r) => FakeAnalyzer::success($r));

        $this->assertSame(AnalysisFailure::AnalyzerAuthFailed, $e->failure);
        Http::assertNothingSent();
    }
}
