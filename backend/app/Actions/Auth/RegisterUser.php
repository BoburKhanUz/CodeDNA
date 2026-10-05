<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Creates an account and signs the new user in.
 *
 * No explicit transaction: registration performs a single insert. Wrap this
 * in a transaction once it writes more than one row (e.g. a profile).
 */
final readonly class RegisterUser
{
    public function __construct(
        private StartUserSession $startUserSession,
        private Dispatcher $events,
    ) {}

    public function handle(string $name, string $email, #[SensitiveParameter] string $password, Session $session): User
    {
        try {
            // The "hashed" cast hashes the password with the configured hasher (bcrypt).
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration won the race after validation passed.
            throw ValidationException::withMessages([
                'email' => [trans('validation.unique', ['attribute' => 'email'])],
            ]);
        }

        $this->events->dispatch(new Registered($user));
        $this->startUserSession->handle($user, $session);

        return $user;
    }
}
