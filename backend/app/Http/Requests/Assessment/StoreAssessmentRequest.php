<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/projects/{project}/assessments
 *
 *     {} or { "skill_gap_snapshot_id": "<ULID>" }
 *
 * The client only selects which stored skill gap snapshot to interpret
 * (default: the newest). There is no prompt, model, provider, evidence,
 * score, target or instruction field: any other field is rejected (422),
 * so nothing a client sends can reach the provider.
 */
final class StoreAssessmentRequest extends FormRequest
{
    private const FIELDS = ['skill_gap_snapshot_id'];

    /**
     * Owner only; non-owners get 404 before any validation (ProjectPolicy).
     */
    public function authorize(): Response
    {
        return Gate::inspect('assess', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'skill_gap_snapshot_id' => ['sometimes', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Unknown field names are not echoed back.
                if (array_diff(array_map('strval', array_keys($this->all())), self::FIELDS) !== []) {
                    $validator->errors()->add('request', 'Only skill_gap_snapshot_id is accepted.');
                }
            },
        ];
    }

    public function skillGapSnapshotId(): ?string
    {
        $id = $this->validated('skill_gap_snapshot_id');

        return is_string($id) ? strtolower($id) : null;
    }
}
