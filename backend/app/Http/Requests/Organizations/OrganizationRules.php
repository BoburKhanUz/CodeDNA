<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

/**
 * Field rules shared by organization requests; they mirror the
 * organizations CHECK constraints (Phase 24).
 */
final class OrganizationRules
{
    /**
     * @return list<mixed>
     */
    public static function name(): array
    {
        return ['string', 'min:1', 'max:100', 'not_regex:/^\s|\s$/'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['name.not_regex' => 'The name must not start or end with whitespace.'];
    }
}
