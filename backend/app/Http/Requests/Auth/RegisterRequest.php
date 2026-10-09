<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service registration, subject to the installation's registration mode
 * (Phase 27, docs/enterprise/configuration-reference.md#registration):
 * "closed" refuses every new account before anything is validated;
 * "restricted" accepts only addresses in the allowed email domains (exact
 * domain match, no subdomains). Enforced here, on the server, whatever the
 * frontend shows.
 */
final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('codedna.registration.mode') !== 'closed';
    }

    protected function failedAuthorization(): never
    {
        throw new ApiException(ErrorCode::RegistrationClosed);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', $this->allowedDomain(...), Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /** @param  Closure(string): void  $fail */
    private function allowedDomain(string $attribute, mixed $value, Closure $fail): void
    {
        if (config('codedna.registration.mode') !== 'restricted' || ! is_string($value)) {
            return;
        }
        $domain = substr(strrchr($value, '@') ?: '', 1);
        if (! in_array($domain, (array) config('codedna.registration.allowed_email_domains'), true)) {
            $fail('Registration on this installation is limited to approved email domains.');
        }
    }

    /**
     * Emails are compared case-insensitively: they are stored lowercase.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
