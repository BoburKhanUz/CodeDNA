<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AnalysisFailure;
use App\Enums\AnalysisRunStatus;
use App\Models\AnalysisRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an analysis run. Never includes the analyzer URL,
 * request signatures, pre-signed source URLs, storage keys, attempt
 * metadata or raw analyzer errors. A failed run shows only its failure code
 * and the fixed message for that code. The result itself is served by
 * GET .../analyses/{run}/result.
 *
 * @mixin AnalysisRun
 */
final class AnalysisRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $succeeded = $this->status === AnalysisRunStatus::Succeeded;
        $failed = $this->status === AnalysisRunStatus::Failed;

        return [
            'id' => $this->id,
            'type' => 'analysis_run',
            'project_id' => $this->project_id,
            'source_snapshot_id' => $this->source_snapshot_id,
            'result_type' => $this->result_type->value,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'failure' => $failed ? [
                'code' => $this->failure_code,
                'message' => AnalysisFailure::tryFrom((string) $this->failure_code)?->message() ?? AnalysisFailure::AnalysisFailed->message(),
            ] : null,
            'result' => $succeeded ? [
                'result_hash' => $this->result_hash,
                'versions' => [
                    'contract' => $this->contract_version,
                    'analyzer' => $this->analyzer_version,
                    'ir' => $this->ir_version,
                    'metrics' => $this->metrics_version,
                ],
            ] : null,
        ];
    }
}
