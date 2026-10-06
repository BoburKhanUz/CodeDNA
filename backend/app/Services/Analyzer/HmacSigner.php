<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

/**
 * HMAC-SHA256 signing of analyzer requests and verification of its
 * responses, exactly as frozen in the internal analyzer contract (section 3):
 *
 *     request:  v1\n<timestamp>\n<METHOD>\n<path>\n<X-Request-ID>\n<hex sha256(body)>
 *     response: v1\n<timestamp>\n<HTTP status>\n<request path>\n<X-Request-ID>\n<hex sha256(body)>
 *
 * Header value: "v1=" followed by the lowercase hex HMAC. Comparison is
 * constant time. During secret rotation a response signed with the previous
 * secret is also accepted.
 */
final readonly class HmacSigner
{
    public const HEADER_TIMESTAMP = 'X-CodeDNA-Timestamp';

    public const HEADER_SIGNATURE = 'X-CodeDNA-Signature';

    public const HEADER_REQUEST_ID = 'X-Request-ID';

    /**
     * @param  list<string>  $secrets  current secret first, then the previous one (rotation)
     */
    public function __construct(
        private array $secrets,
        private int $maxSkewSeconds,
    ) {}

    public static function canonicalRequest(int $timestamp, string $method, string $path, string $requestId, string $body): string
    {
        return implode("\n", ['v1', (string) $timestamp, strtoupper($method), $path, $requestId, hash('sha256', $body)]);
    }

    public static function canonicalResponse(string $timestamp, int $status, string $path, string $requestId, string $body): string
    {
        return implode("\n", ['v1', $timestamp, (string) $status, $path, $requestId, hash('sha256', $body)]);
    }

    /**
     * @return array<string, string> headers for the request
     */
    public function signRequest(int $timestamp, string $method, string $path, string $requestId, string $body): array
    {
        $canonical = self::canonicalRequest($timestamp, $method, $path, $requestId, $body);

        return [
            self::HEADER_REQUEST_ID => $requestId,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_SIGNATURE => 'v1='.hash_hmac('sha256', $canonical, $this->secrets[0]),
        ];
    }

    /**
     * True when the response carries a fresh signature made with one of the
     * configured secrets over exactly these status, path, request ID and body.
     */
    public function verifyResponse(?string $timestamp, ?string $signature, int $status, string $path, string $requestId, string $body, int $now): bool
    {
        if ($timestamp === null || $signature === null || preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1) {
            return false;
        }
        if (abs($now - (int) $timestamp) > $this->maxSkewSeconds) {
            return false;
        }
        if (preg_match('/^v1=[0-9a-f]{64}$/', $signature) !== 1) {
            return false;
        }

        $canonical = self::canonicalResponse($timestamp, $status, $path, $requestId, $body);
        $valid = false;
        foreach ($this->secrets as $secret) {
            // No early exit: every configured secret is compared.
            $valid = hash_equals('v1='.hash_hmac('sha256', $canonical, $secret), $signature) || $valid;
        }

        return $valid;
    }
}
