<?php

declare(strict_types=1);

namespace App\Http\Requests\Challenge;

use App\Enums\Competency\CompetencyKey;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/projects/{project}/challenges
 *
 *     {} or { "skill_gap_snapshot_id": "<ULID>", "competency_key": "FUNCTION_DESIGN" }
 *
 * The client only selects which stored skill gap (and optionally which
 * competency) to practice. It can never supply a challenge definition,
 * test, command, image, runtime or difficulty: any other field is rejected.
 */
final class StoreChallengeRequest extends FormRequest
{
    private const FIELDS = ['skill_gap_snapshot_id', 'competency_key'];

    /**
     * Owner only; non-owners get 404 before any validation (ProjectPolicy).
     */
    public function authorize(): Response
    {
        return Gate::inspect('practice', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'skill_gap_snapshot_id' => ['sometimes', 'string', 'ulid'],
            'competency_key' => ['sometimes', 'string', Rule::in(array_column(CompetencyKey::cases(), 'value'))],
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
                    $validator->errors()->add('request', 'Only skill_gap_snapshot_id and competency_key are accepted.');
                }
            },
        ];
    }

    public function skillGapSnapshotId(): ?string
    {
        $id = $this->validated('skill_gap_snapshot_id');

        return is_string($id) ? strtolower($id) : null;
    }

    public function competencyKey(): ?string
    {
        $key = $this->validated('competency_key');

        return is_string($key) ? $key : null;
    }
}
