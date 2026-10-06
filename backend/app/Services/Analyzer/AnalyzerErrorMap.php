<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use App\Enums\AnalysisFailure;

/**
 * Maps a signed analyzer error envelope (internal contract, section 5) onto
 * the run's failure code and Laravel's retry decision
 * (docs/architecture/data-flow.md#retry-matrix).
 *
 * The retry decision is Laravel's own table, not the envelope's `retryable`
 * flag, so a misbehaving analyzer cannot force retries. The two agree for
 * every documented code.
 */
final class AnalyzerErrorMap
{
    /**
     * @return array{0: AnalysisFailure, 1: bool} failure code and whether to retry
     */
    public static function map(string $analyzerCode): array
    {
        return match ($analyzerCode) {
            // The analyzer could not authenticate our request: configuration, never transient.
            'INVALID_SIGNATURE', 'STALE_TIMESTAMP', 'REPLAY_DETECTED' => [AnalysisFailure::AnalyzerAuthFailed, false],
            // Requests Laravel built wrongly; repeating them cannot help.
            'UNSUPPORTED_CONTRACT_VERSION', 'NOT_FOUND', 'METHOD_NOT_ALLOWED', 'INVALID_REQUEST', 'RUN_CONFLICT' => [AnalysisFailure::AnalysisFailed, false],
            // The analyzer's verdict on the source: deterministic, passed through.
            'SOURCE_TOO_LARGE' => [AnalysisFailure::SourceTooLarge, false],
            'TOO_MANY_FILES' => [AnalysisFailure::TooManyFiles, false],
            'INVALID_ARCHIVE' => [AnalysisFailure::InvalidArchive, false],
            'NO_SUPPORTED_FILES' => [AnalysisFailure::NoSupportedFiles, false],
            'SOURCE_CHECKSUM_MISMATCH' => [AnalysisFailure::SourceChecksumMismatch, false],
            'ANALYSIS_TIMEOUT' => [AnalysisFailure::AnalysisTimeout, false],
            'SOURCE_HOST_NOT_ALLOWED' => [AnalysisFailure::SourceUnavailable, false],
            // Transient.
            'SOURCE_URL_EXPIRED' => [AnalysisFailure::SourceUrlExpired, true],
            'SOURCE_FETCH_FAILED' => [AnalysisFailure::SourceUnavailable, true],
            'RUN_IN_PROGRESS', 'ANALYZER_BUSY', 'INTERNAL_ERROR' => [AnalysisFailure::AnalyzerUnavailable, true],
            default => [AnalysisFailure::AnalyzerInvalidResponse, false],
        };
    }
}
