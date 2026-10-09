<?php

declare(strict_types=1);

namespace App\Http\Requests\Insights;

use App\Enums\Insights\InsightKind;
use App\Http\Requests\Concerns\AuthorizesProjectView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/projects/{project}/insights?kind=...&subject_id=...
 * Both filters are required: a page shows the insights of one subject.
 */
final class ListInsightsRequest extends FormRequest
{
    // Another user's project answers 404 before the query is validated.
    use AuthorizesProjectView;

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

    public function kind(): InsightKind
    {
        return InsightKind::from((string) $this->validated('kind'));
    }

    public function subjectId(): string
    {
        return strtolower((string) $this->validated('subject_id'));
    }
}
