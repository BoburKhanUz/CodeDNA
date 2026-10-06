<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\SourceType;
use App\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /api/v1/projects/{project} — partial update of descriptive fields.
 *
 * `status` and `source_type` are rejected explicitly (archive has its own
 * endpoint; the source type is fixed at creation). Other unknown fields
 * (id, user_id, metadata, ...) are ignored.
 */
final class UpdateProjectRequest extends FormRequest
{
    /**
     * Owner only; non-owners get 404 before any validation (ProjectPolicy).
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');
        $isRepository = $project->source_type === SourceType::Repository;

        return [
            'name' => ['sometimes', 'required', ...ProjectRules::name()],
            'slug' => ['sometimes', 'required', ...ProjectRules::slug($project->user_id, $project->id)],
            'description' => ['sometimes', ...ProjectRules::description()],
            'default_branch' => ['sometimes', ...ProjectRules::defaultBranch()],
            'repository_url' => $isRepository
                ? ['sometimes', 'required', ...ProjectRules::repositoryUrl()]
                : ['prohibited'],
            'language' => ['sometimes', ...ProjectRules::language()],
            'status' => ['prohibited'],
            'source_type' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ProjectRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ProjectRules::normalizedSlug($this->all()));
    }
}
