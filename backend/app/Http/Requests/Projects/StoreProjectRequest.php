<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\SourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/projects. Only validated() data reaches the model: the owner
 * is the authenticated user, the status starts ACTIVE, and any other field
 * (id, user_id, status, metadata, ...) is ignored.
 */
class StoreProjectRequest extends FormRequest
{
    /** The organization a team project is created in (Phase 24); null for a personal project. */
    protected function organizationId(): ?string
    {
        return null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', ...ProjectRules::name()],
            'slug' => ['required', ...ProjectRules::slug((string) $this->user()?->getAuthIdentifier(), null, $this->organizationId())],
            'description' => ProjectRules::description(),
            'default_branch' => ProjectRules::defaultBranch(),
            'source_type' => ['required', 'string', Rule::enum(SourceType::class)],
            'repository_url' => [
                'required_if:source_type,'.SourceType::Repository->value,
                'prohibited_unless:source_type,'.SourceType::Repository->value,
                ...ProjectRules::repositoryUrl(),
            ],
            'language' => ProjectRules::language(),
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
