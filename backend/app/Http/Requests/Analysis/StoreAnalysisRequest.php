<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use App\Enums\AnalysisResultType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/projects/{project}/analyses
 *
 *     { "source_snapshot_id": "<ULID>", "result_type": "foundation" | "static_analysis" }
 *
 * result_type is optional and defaults to "foundation" (the analyzer's
 * default). The client only selects an existing snapshot of this project:
 * no URL, storage key or analyzer option can be supplied. Whether the
 * snapshot belongs to the project is decided by StartAnalysis under lock.
 */
final class StoreAnalysisRequest extends FormRequest
{
    /**
     * Owner only; non-owners get 404 before any validation (ProjectPolicy).
     */
    public function authorize(): Response
    {
        return Gate::inspect('analyze', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'source_snapshot_id' => ['required', 'string', 'ulid'],
            'result_type' => ['sometimes', 'string', Rule::in(AnalysisResultType::values())],
        ];
    }

    public function resultType(): AnalysisResultType
    {
        return AnalysisResultType::from((string) $this->validated('result_type', AnalysisResultType::default()->value));
    }

    public function sourceSnapshotId(): string
    {
        return strtolower((string) $this->validated('source_snapshot_id'));
    }
}
