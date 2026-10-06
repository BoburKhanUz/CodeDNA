<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * PATCH /api/v1/auth/password.
 *
 * The new password follows the registration policy (Password::defaults()).
 * Password values never appear in errors, logs or flashed input: Laravel's
 * exception handler never flashes password, password_confirmation or
 * current_password.
 */
final class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Checked against the authenticated user's hash with the configured hasher.
            'current_password' => ['required', 'string', 'max:255', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::defaults(), 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
            'password.different' => 'The new password must be different from the current password.',
        ];
    }
}
