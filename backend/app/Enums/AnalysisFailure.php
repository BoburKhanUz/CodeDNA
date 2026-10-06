<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Failure codes stored on FAILED analysis runs (analysis_runs.failure_code)
 * and shown to API clients with a fixed, user-safe message. Raw analyzer
 * output, exception text, URLs and stack traces are never stored or shown.
 *
 * Analyzer errors that describe the user's source are passed through under
 * the analyzer's own code (internal contract section 5); every other
 * failure is mapped onto one of the pipeline codes below
 * (docs/architecture/data-flow.md#failure-model).
 */
enum AnalysisFailure: string
{
    // Pipeline (Laravel side).
    case AnalyzerUnavailable = 'ANALYZER_UNAVAILABLE';
    case AnalyzerTimeout = 'ANALYZER_TIMEOUT';
    case AnalyzerAuthFailed = 'ANALYZER_AUTH_FAILED';
    case AnalyzerInvalidResponse = 'ANALYZER_INVALID_RESPONSE';
    case AnalyzerResultInvalid = 'ANALYZER_RESULT_INVALID';
    case AnalyzerResultHashMismatch = 'ANALYZER_RESULT_HASH_MISMATCH';
    case SourceUnavailable = 'SOURCE_UNAVAILABLE';
    case SourceUrlExpired = 'SOURCE_URL_EXPIRED';
    case DispatchFailed = 'DISPATCH_FAILED';
    case AnalysisStale = 'ANALYSIS_STALE';
    case AnalysisFailed = 'ANALYSIS_FAILED';
    // The analyzer's verdict on the source itself (passed through).
    case SourceTooLarge = 'SOURCE_TOO_LARGE';
    case TooManyFiles = 'TOO_MANY_FILES';
    case InvalidArchive = 'INVALID_ARCHIVE';
    case NoSupportedFiles = 'NO_SUPPORTED_FILES';
    case SourceChecksumMismatch = 'SOURCE_CHECKSUM_MISMATCH';
    case AnalysisTimeout = 'ANALYSIS_TIMEOUT';

    public function message(): string
    {
        return match ($this) {
            self::AnalyzerUnavailable => 'The analysis service is temporarily unavailable.',
            self::AnalyzerTimeout => 'The analysis service did not respond in time.',
            self::AnalyzerAuthFailed => 'The analysis service could not be authenticated.',
            self::AnalyzerInvalidResponse => 'The analysis service returned an invalid response.',
            self::AnalyzerResultInvalid => 'The analysis result failed validation.',
            self::AnalyzerResultHashMismatch => 'The analysis result failed its integrity check.',
            self::SourceUnavailable => 'The source archive could not be read.',
            self::SourceUrlExpired => 'Access to the source archive expired.',
            self::DispatchFailed => 'The analysis could not be queued.',
            self::AnalysisStale => 'The analysis did not finish and was stopped.',
            self::AnalysisFailed => 'The analysis failed.',
            self::SourceTooLarge => 'The source archive exceeds the analysis size limits.',
            self::TooManyFiles => 'The source archive contains too many files to analyze.',
            self::InvalidArchive => 'The source archive is corrupt or contains unsafe entries.',
            self::NoSupportedFiles => 'The source archive contains no files in a supported language.',
            self::SourceChecksumMismatch => 'The stored source archive does not match its recorded checksum.',
            self::AnalysisTimeout => 'The analysis exceeded its time limit.',
        };
    }
}
