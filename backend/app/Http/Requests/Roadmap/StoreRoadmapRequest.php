<?php

declare(strict_types=1);

namespace App\Http\Requests\Roadmap;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/projects/{project}/roadmaps
 *
 *     {}
 *
 * Generates the roadmap of the newest skill gap analysis. The client sends
 * nothing: tracks, steps, targets, gaps, priorities and versions are all
 * server-owned, so any field is rejected.
 */
final class StoreRoadmapRequest extends FormRequest
{
    /**
     * Owner only; non-owners get 404 before any validation (ProjectPolicy).
     */
    public function authorize(): Response
    {
        return Gate::inspect('plan', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Field names are not echoed back.
                if ($this->all() !== []) {
                    $validator->errors()->add('request', 'This request accepts no fields.');
                }
            },
        ];
    }
}
