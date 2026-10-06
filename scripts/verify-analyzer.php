<?php

/*
 * Internal integration check for `make verify` (Phase 08): Laravel → analyzer
 * over the private Docker network, exactly as the contract specifies.
 *
 * Runs inside the backend container. It stores a small ZIP (passed in
 * VERIFY_ZIP_BASE64) in the source bucket, pre-signs it with Laravel's own
 * storage disk, signs the request with ANALYZER_HMAC_SECRET and calls
 * POST /internal/v1/analyze. Prints one "PASS|FAIL <check>" line per check
 * and never prints URLs, signatures or secrets. The object is always deleted.
 *
 * This is verification tooling, not the Phase 10 analyzer client.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require '/var/www/backend/vendor/autoload.php';
$app = require '/var/www/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$secret = (string) getenv('ANALYZER_HMAC_SECRET');
$zip = base64_decode((string) getenv('VERIFY_ZIP_BASE64'), true);
$base = 'http://analyzer:8000';
$path = '/internal/v1/analyze';
$failures = 0;

$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL').' '.$name.PHP_EOL;
    $failures += $ok ? 0 : 1;
};

$sign = static function (string $body, string $requestId, ?int $timestamp = null) use ($secret, $path): array {
    $timestamp ??= time();
    $canonical = implode("\n", ['v1', (string) $timestamp, 'POST', $path, $requestId, hash('sha256', $body)]);

    return [
        'X-Request-ID' => $requestId,
        'X-CodeDNA-Timestamp' => (string) $timestamp,
        'X-CodeDNA-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret),
    ];
};

$call = static function (array $payload, ?array $headers = null) use ($base, $path, $sign): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestId = (string) Str::uuid();
    $headers ??= $sign($body, $requestId);
    $response = Http::withHeaders($headers + ['Idempotency-Key' => $payload['analysis_run_id']])
        ->withBody($body, 'application/json')
        ->timeout(60)
        ->post($base.$path);

    return [$response, $headers['X-Request-ID'], $body];
};

$responseSignatureValid = static function ($response, string $requestId) use ($secret, $path): bool {
    $timestamp = (string) $response->header('X-CodeDNA-Timestamp');
    $canonical = implode("\n", ['v1', $timestamp, (string) $response->status(), $path, $requestId, hash('sha256', $response->body())]);

    return hash_equals('v1='.hash_hmac('sha256', $canonical, $secret), (string) $response->header('X-CodeDNA-Signature'));
};

if ($secret === '' || $zip === false || $zip === '') {
    echo 'FAIL analyzer secret and test archive are available'.PHP_EOL;
    exit(1);
}

$project = strtolower((string) Str::ulid());
$snapshot = strtolower((string) Str::ulid());
$runId = strtolower((string) Str::ulid());
$key = "verify-analyzer/projects/{$project}/snapshots/{$snapshot}/source.zip";
$disk = Storage::disk('sources');

try {
    $disk->put($key, $zip);
    $payload = [
        'contract_version' => '1.0',
        'analysis_run_id' => $runId,
        'attempt' => 1,
        'source' => [
            'type' => 'archive',
            'format' => 'zip',
            'url' => $disk->temporaryUrl($key, now()->addMinutes(5)),
            'sha256' => hash('sha256', $zip),
            'size_bytes' => strlen($zip),
        ],
    ];

    [$first, $firstId] = $call($payload);
    $data = $first->json();
    $check('signed request -> 200 foundation result', $first->status() === 200 && ($data['result_type'] ?? null) === 'foundation');
    $check('response signature verifies', $responseSignatureValid($first, $firstId));
    $check('contract and IR versions present', ($data['contract_version'] ?? null) === '1.0' && ($data['versions']['ir'] ?? null) === '1.0');
    $check('source downloaded, verified and discovered (3 files, php + python)',
        ($data['source']['files_total'] ?? null) === 3
        && array_column($data['languages'] ?? [], 'language') === ['php', 'python']);
    $check('no scores, metrics or source text in the result',
        ! isset($data['dna']) && ! isset($data['metrics']) && ! str_contains($first->body(), 'echo')
        && ! str_contains($first->body(), 'X-Amz'));

    // A fresh URL and request ID for the same run: same deterministic result.
    $payload['attempt'] = 2;
    $payload['source']['url'] = $disk->temporaryUrl($key, now()->addMinutes(5));
    [$retry] = $call($payload);
    $check('retry of the same run returns the same result_hash',
        $retry->status() === 200 && $retry->json('result_hash') === ($data['result_hash'] ?? 'missing'));

    [$tampered] = $call($payload, $sign('{"tampered":true}', (string) Str::uuid()));
    $check('wrong signature -> 401 INVALID_SIGNATURE', $tampered->status() === 401 && $tampered->json('error.code') === 'INVALID_SIGNATURE');

    [$stale] = $call($payload, $sign(json_encode($payload, JSON_UNESCAPED_SLASHES), (string) Str::uuid(), time() - 900));
    $check('stale timestamp -> 401 STALE_TIMESTAMP', $stale->status() === 401 && $stale->json('error.code') === 'STALE_TIMESTAMP');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $replayHeaders = $sign($body, (string) Str::uuid());
    $call($payload, $replayHeaders);
    [$replay] = $call($payload, $replayHeaders);
    $check('replayed request -> 401 REPLAY_DETECTED', $replay->json('error.code') === 'REPLAY_DETECTED');

    $conflict = $payload;
    $conflict['source']['sha256'] = str_repeat('0', 64);
    [$conflicting] = $call($conflict);
    $check('same run, different source -> 409 RUN_CONFLICT', $conflicting->status() === 409 && $conflicting->json('error.code') === 'RUN_CONFLICT');

    $ssrf = $payload;
    $ssrf['analysis_run_id'] = strtolower((string) Str::ulid());
    $ssrf['source']['url'] = 'http://169.254.169.254/latest/meta-data/';
    [$metadata] = $call($ssrf);
    $check('metadata-service URL -> 422 SOURCE_HOST_NOT_ALLOWED', $metadata->json('error.code') === 'SOURCE_HOST_NOT_ALLOWED');
} catch (Throwable $e) {
    $check('integration call completed without exceptions ('.$e::class.')', false);
} finally {
    $disk->delete($key);
    $check('test object removed from storage', ! $disk->exists($key));
}

exit($failures === 0 ? 0 : 1);
