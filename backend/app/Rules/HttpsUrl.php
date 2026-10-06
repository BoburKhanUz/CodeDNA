<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An absolute https:// URL with a host and no embedded credentials.
 *
 * Profile links are shown to the browser, so other schemes (javascript:,
 * data:, http:) and user:password@ URLs are rejected.
 */
final class HttpsUrl implements ValidationRule
{
    public const MAX_LENGTH = 2048;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isValid($value)) {
            $fail('The :attribute must be a valid https:// URL.');
        }
    }

    private function isValid(string $value): bool
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
