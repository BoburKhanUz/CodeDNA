<?php

declare(strict_types=1);

namespace App\Http\Requests\Insights;

use App\Enums\Insights\InsightKind;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/projects/{project}/insights
 *
 *     { "kind": "GROWTH_INTERPRETATION", "subject_id": "<ULID>" }
 *
 * The client only names what to interpret. There is no prompt, model,
 * provider, URL, evidence, score or instruction field: any other field is
 * rejected (422), so nothing a client sends reaches the model.
 */
final class StoreInsightRequest extends FormRequest
{
    private const FIELDS = ['kind', 'subject_id'];

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
            'kind' => ['required', 'string', Rule::in(InsightKind::values())],
            'subject_id' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_diff(array_map('strval', array_keys($this->all())), self::FIELDS) !== []) {
                    $validator->errors()->add('request', 'Only kind and subject_id are accepted.');
                }
            },
        ];
    }

    public function kind(): InsightKind
    {
        return InsightKind::from((string) $this->validated('kind'));
    }

    public function subjectId(): string
    {
        return strtolower((string) $this->validated('subject_id'));
    }
}
