<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\ProgrammingLanguage;
use App\Rules\HttpsUrl;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by project creation and update. They mirror the
 * database CHECK constraints of `projects` (Phase 05) and add the
 * application-level rules (lengths, allowed values, uniqueness).
 */
final class ProjectRules
{
    /** Same as the projects_slug_format CHECK constraint. */
    public const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * A conservative subset of git's ref-name rules: starts with an
     * alphanumeric, then letters, digits, ".", "_", "-" and "/"; no "..",
     * "//", "@{", trailing "/", "." or ".lock".
     */
    public const BRANCH_PATTERN = '#^(?!.*\.\.)(?!.*//)(?!.*@\{)(?!.*\.lock$)[A-Za-z0-9][A-Za-z0-9._/-]*(?<![/.])$#';

    /**
     * @return list<mixed>
     */
    public static function name(): array
    {
        return ['string', 'max:255'];
    }

    /**
     * Slugs are unique per owner among personal projects, and per
     * organization among an organization's projects (Phase 24).
     *
     * @return list<mixed>
     */
    public static function slug(string $userId, ?string $ignoreProjectId = null, ?string $organizationId = null): array
    {
        $unique = $organizationId === null
            ? Rule::unique('projects', 'slug')->where('user_id', $userId)->whereNull('organization_id')
            : Rule::unique('projects', 'slug')->where('organization_id', $organizationId);
        if ($ignoreProjectId !== null) {
            $unique = $unique->ignore($ignoreProjectId);
        }

        return ['string', 'max:100', 'regex:'.self::SLUG_PATTERN, $unique];
    }

    /**
     * @return list<mixed>
     */
    public static function description(): array
    {
        return ['nullable', 'string', 'max:2000'];
    }

    /**
     * @return list<mixed>
     */
    public static function defaultBranch(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:'.self::BRANCH_PATTERN];
    }

    /**
     * @return list<mixed>
     */
    public static function repositoryUrl(): array
    {
        return ['nullable', 'string', new HttpsUrl];
    }

    /**
     * @return list<mixed>
     */
    public static function language(): array
    {
        return ['nullable', 'string', Rule::enum(ProgrammingLanguage::class)];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, digits and single hyphens.',
            'slug.unique' => 'You already have a project with this slug.',
            'default_branch.regex' => 'The default branch must be a valid branch name, such as main.',
            'repository_url.required_if' => 'A repository URL is required for repository projects.',
            'repository_url.prohibited_unless' => 'Upload projects do not have a repository URL.',
            'repository_url.prohibited' => 'Upload projects do not have a repository URL.',
            'status.prohibited' => 'Use POST /api/v1/projects/{project}/archive to archive a project.',
            'source_type.prohibited' => 'The source type of a project cannot be changed.',
        ];
    }

    /**
     * Slugs are compared lowercase (the CHECK constraint allows only
     * lowercase), so "My-App" is accepted as "my-app".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizedSlug(array $input): array
    {
        return isset($input['slug']) && is_string($input['slug']) ? ['slug' => Str::lower($input['slug'])] : [];
    }
}
