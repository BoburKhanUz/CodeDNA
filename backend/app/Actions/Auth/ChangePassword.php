<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Sets a new password and ends the current session.
 *
 * The caller has already verified the current password
 * (ChangePasswordRequest). Afterwards:
 *
 * - the current session is invalidated (the user signs in again);
 * - every other session of the user is rejected on its next request,
 *   because Sanctum's AuthenticateSession middleware compares the password
 *   hash stored in each session with the new one;
 * - the remember-me token is rotated.
 */
final readonly class ChangePassword
{
    public function __construct(private LogoutUser $logoutUser) {}

    public function handle(User $user, #[SensitiveParameter] string $newPassword, Session $session): void
    {
        // The "hashed" cast hashes the password with the configured hasher (bcrypt).
        $user->forceFill([
            'password' => $newPassword,
            'remember_token' => Str::random(60),
        ])->save();

        // Audit trail without secrets: who and when, never what.
        Log::info('Password changed.', ['user_id' => $user->id]);

        $this->logoutUser->handle($session);
    }
}
