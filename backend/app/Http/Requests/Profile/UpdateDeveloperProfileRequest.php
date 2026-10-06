<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\ProgrammingLanguage;
use App\Enums\SupportedLocale;
use App\Rules\HttpsUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/profile — partial update: only the fields sent are changed.
 *
 * Optional fields accept null (or an empty string, which Laravel converts to
 * null) to clear them. Fields not listed here (id, user_id, metadata, ...)
 * are ignored: only validated() data reaches the model.
 */
final class UpdateDeveloperProfileRequest extends FormRequest
{
    /** GitHub: 1–39 alphanumerics or single hyphens, not at either end. */
    public const GITHUB_USERNAME_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'avatar_url' => ['sometimes', 'nullable', 'string', new HttpsUrl],
            // Canonical IANA identifiers only ("Asia/Tashkent"), not offsets ("UTC+5").
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone:all'],
            'locale' => ['sometimes', 'required', 'string', Rule::enum(SupportedLocale::class)],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website_url' => ['sometimes', 'nullable', 'string', new HttpsUrl],
            'github_username' => ['sometimes', 'nullable', 'string', 'max:39', 'regex:'.self::GITHUB_USERNAME_PATTERN],
            'linkedin_url' => ['sometimes', 'nullable', 'string', new HttpsUrl],
            'preferred_language' => ['sometimes', 'nullable', 'string', Rule::enum(ProgrammingLanguage::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'The timezone must be a valid IANA time zone, such as Asia/Tashkent.',
            'country_code.regex' => 'The country code must be a two-letter code, such as UZ.',
            'github_username.regex' => 'The GitHub username may contain only letters, digits and single hyphens, and cannot start or end with a hyphen.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $country = $this->input('country_code');
        if (is_string($country)) {
            $this->merge(['country_code' => strtoupper($country)]);
        }

        // "@octocat" is a common way to write a GitHub handle.
        $github = $this->input('github_username');
        if (is_string($github) && str_starts_with($github, '@')) {
            $this->merge(['github_username' => substr($github, 1)]);
        }
    }
}
