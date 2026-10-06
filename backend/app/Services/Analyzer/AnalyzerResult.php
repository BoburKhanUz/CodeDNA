<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use App\Enums\AnalysisResultType;
use App\Support\AnalysisVersions;
use stdClass;

/**
 * A fully verified analyzer result: signature, schema, identity and
 * result_hash have all been checked by AnalyzerClient.
 */
final readonly class AnalyzerResult
{
    public function __construct(
        public AnalysisResultType $resultType,
        public string $resultHash,
        public AnalysisVersions $versions,
        public stdClass $result,
        // The exact response body that was verified (stored as is).
        public string $rawBody,
        public int $sizeBytes,
        public bool $replayed,
    ) {}
}
