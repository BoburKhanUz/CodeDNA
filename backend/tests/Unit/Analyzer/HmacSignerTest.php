<?php

declare(strict_types=1);

namespace Tests\Unit\Analyzer;

use App\Services\Analyzer\HmacSigner;
use PHPUnit\Framework\TestCase;

/**
 * Signatures are checked against vectors produced by the analyzer's own
 * signing code (tests/Fixtures/analyzer/hmac-vectors.json), so Laravel and
 * the analyzer cannot drift apart.
 */
final class HmacSignerTest extends TestCase
{
    private const SECRET = 'tttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt';

    private const PREVIOUS = 'pppppppppppppppppppppppppppppppppppppppppppppppppppppppppppppppp';

    /** @return object{timestamp: int, request_id: string, body: string, request_signature: string, response_signature: string} */
    private function vector(): object
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/analyzer/hmac-vectors.json'), false);
    }

    public function test_request_signatures_match_the_analyzer(): void
    {
        $v = $this->vector();
        $headers = (new HmacSigner([self::SECRET], 300))->signRequest($v->timestamp, 'POST', '/internal/v1/analyze', $v->request_id, $v->body);

        $this->assertSame([
            'X-Request-ID' => $v->request_id,
            'X-CodeDNA-Timestamp' => (string) $v->timestamp,
            'X-CodeDNA-Signature' => $v->request_signature,
        ], $headers);
    }

    public function test_analyzer_response_signatures_verify(): void
    {
        $v = $this->vector();
        $signer = new HmacSigner([self::SECRET], 300);
        $verify = fn (...$args) => $signer->verifyResponse(...$args);

        $this->assertTrue($verify((string) $v->timestamp, $v->response_signature, 200, '/internal/v1/analyze', $v->request_id, $v->body, $v->timestamp + 10));
        // Any change to status, path, request ID, body or timestamp invalidates it.
        $this->assertFalse($verify((string) $v->timestamp, $v->response_signature, 201, '/internal/v1/analyze', $v->request_id, $v->body, $v->timestamp));
        $this->assertFalse($verify((string) $v->timestamp, $v->response_signature, 200, '/internal/v1/other', $v->request_id, $v->body, $v->timestamp));
        $this->assertFalse($verify((string) $v->timestamp, $v->response_signature, 200, '/internal/v1/analyze', strrev($v->request_id), $v->body, $v->timestamp));
        $this->assertFalse($verify((string) $v->timestamp, $v->response_signature, 200, '/internal/v1/analyze', $v->request_id, $v->body.' ', $v->timestamp));
        $this->assertFalse($verify((string) ($v->timestamp + 1), $v->response_signature, 200, '/internal/v1/analyze', $v->request_id, $v->body, $v->timestamp));
    }

    public function test_stale_missing_and_malformed_signatures_are_rejected(): void
    {
        $v = $this->vector();
        $signer = new HmacSigner([self::SECRET], 300);
        $args = [200, '/internal/v1/analyze', $v->request_id, $v->body];

        $this->assertFalse($signer->verifyResponse((string) $v->timestamp, $v->response_signature, ...[...$args, $v->timestamp + 301]));
        $this->assertFalse($signer->verifyResponse((string) $v->timestamp, $v->response_signature, ...[...$args, $v->timestamp - 301]));
        $this->assertFalse($signer->verifyResponse(null, $v->response_signature, ...[...$args, $v->timestamp]));
        $this->assertFalse($signer->verifyResponse((string) $v->timestamp, null, ...[...$args, $v->timestamp]));
        $this->assertFalse($signer->verifyResponse('abc', $v->response_signature, ...[...$args, $v->timestamp]));
        $this->assertFalse($signer->verifyResponse((string) $v->timestamp, strtoupper($v->response_signature), ...[...$args, $v->timestamp]));
        $this->assertFalse($signer->verifyResponse((string) $v->timestamp, 'v2='.substr($v->response_signature, 3), ...[...$args, $v->timestamp]));
    }

    public function test_previous_secret_is_accepted_during_rotation_only(): void
    {
        $v = $this->vector();
        $args = [(string) $v->timestamp, $v->response_signature, 200, '/internal/v1/analyze', $v->request_id, $v->body, $v->timestamp];

        $this->assertTrue((new HmacSigner([self::PREVIOUS, self::SECRET], 300))->verifyResponse(...$args));
        $this->assertFalse((new HmacSigner([self::PREVIOUS], 300))->verifyResponse(...$args));
    }
}
