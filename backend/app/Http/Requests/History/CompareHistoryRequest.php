<?php

declare(strict_types=1);

namespace App\Http\Requests\History;

use App\Http\Requests\Concerns\AuthorizesProjectView;
use App\Http\Requests\GitHub\OnlyFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/projects/{project}/history/compare?from={dnaSnapshot}&to={dnaSnapshot}.
 * Two DNA snapshot IDs and nothing else: scores, deltas, versions and
 * statuses are never accepted from a client. The server checks that both
 * belong to the project and orders them by time.
 */
final class CompareHistoryRequest extends FormRequest
{
    use AuthorizesProjectView;
    use OnlyFields;

    /** IDs are case-insensitive: the same snapshot in two spellings is not two snapshots. */
    protected function prepareForValidation(): void
    {
        foreach (['from', 'to'] as $field) {
            $value = $this->query($field);
            if (is_string($value)) {
                $this->merge([$field => strtolower($value)]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'ulid'],
            'to' => ['required', 'string', 'ulid', 'different:from'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['from', 'to'];
    }

    public function fromId(): string
    {
        return (string) $this->validated('from');
    }

    public function toId(): string
    {
        return (string) $this->validated('to');
    }
}
