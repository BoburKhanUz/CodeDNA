<?php

declare(strict_types=1);

namespace Tests\Unit\Analyzer;

use App\Enums\AnalysisFailure;
use App\Services\Analyzer\AnalyzerErrorMap;
use PHPUnit\Framework\TestCase;

/**
 * The retry matrix for signed analyzer errors (docs/architecture/data-flow.md#retry-matrix).
 */
final class AnalyzerErrorMapTest extends TestCase
{
    public function test_every_analyzer_error_code_has_a_failure_and_a_retry_decision(): void
    {
        $schema = json_decode((string) file_get_contents('/var/www/contracts/analyzer/v1/error.schema.json'), true);
        $codes = $schema['properties']['error']['properties']['code']['enum'];

        $expected = [
            'UNSUPPORTED_CONTRACT_VERSION' => ['ANALYSIS_FAILED', false],
            'INVALID_SIGNATURE' => ['ANALYZER_AUTH_FAILED', false],
            'STALE_TIMESTAMP' => ['ANALYZER_AUTH_FAILED', false],
            'REPLAY_DETECTED' => ['ANALYZER_AUTH_FAILED', false],
            'NOT_FOUND' => ['ANALYSIS_FAILED', false],
            'METHOD_NOT_ALLOWED' => ['ANALYSIS_FAILED', false],
            'RUN_IN_PROGRESS' => ['ANALYZER_UNAVAILABLE', true],
            'RUN_CONFLICT' => ['ANALYSIS_FAILED', false],
            'SOURCE_TOO_LARGE' => ['SOURCE_TOO_LARGE', false],
            'TOO_MANY_FILES' => ['TOO_MANY_FILES', false],
            'INVALID_REQUEST' => ['ANALYSIS_FAILED', false],
            'SOURCE_HOST_NOT_ALLOWED' => ['SOURCE_UNAVAILABLE', false],
            'SOURCE_URL_EXPIRED' => ['SOURCE_URL_EXPIRED', true],
            'SOURCE_CHECKSUM_MISMATCH' => ['SOURCE_CHECKSUM_MISMATCH', false],
            'INVALID_ARCHIVE' => ['INVALID_ARCHIVE', false],
            'NO_SUPPORTED_FILES' => ['NO_SUPPORTED_FILES', false],
            'SOURCE_FETCH_FAILED' => ['SOURCE_UNAVAILABLE', true],
            'ANALYZER_BUSY' => ['ANALYZER_UNAVAILABLE', true],
            'ANALYSIS_TIMEOUT' => ['ANALYSIS_TIMEOUT', false],
            'INTERNAL_ERROR' => ['ANALYZER_UNAVAILABLE', true],
        ];
        $this->assertEqualsCanonicalizing(array_keys($expected), $codes, 'every analyzer code is mapped deliberately');

        foreach ($expected as $code => [$failure, $retryable]) {
            [$actualFailure, $actualRetryable] = AnalyzerErrorMap::map($code);
            $this->assertSame([$failure, $retryable], [$actualFailure->value, $actualRetryable], $code);
        }
    }

    public function test_unknown_codes_are_invalid_responses_and_never_retried(): void
    {
        $this->assertSame([AnalysisFailure::AnalyzerInvalidResponse, false], AnalyzerErrorMap::map('SOMETHING_NEW'));
    }

    public function test_every_failure_has_a_fixed_user_safe_message(): void
    {
        foreach (AnalysisFailure::cases() as $failure) {
            $this->assertMatchesRegularExpression('/^[A-Z][A-Za-z ,.()-]+\.$/', $failure->message(), $failure->value);
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $failure->value);
        }
    }
}
