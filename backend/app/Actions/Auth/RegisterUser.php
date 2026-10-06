<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Creates an account with its developer profile and signs the new user in.
 *
 * The user and the profile are written in one transaction: if the profile
 * cannot be created, the user is rolled back too, so there are no accounts
 * without a profile. The Registered event and the session start only after
 * the commit.
 */
final readonly class RegisterUser
{
    public function __construct(
        private ConnectionInterface $db,
        private StartUserSession $startUserSession,
        private Dispatcher $events,
    ) {}

    public function handle(string $name, string $email, #[SensitiveParameter] string $password, Session $session): User
    {
        try {
            $user = $this->db->transaction(static function () use ($name, $email, $password): User {
                // The "hashed" cast hashes the password with the configured hasher (bcrypt).
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);
                // Defaults only (UTC, "en"); the developer fills in the rest later.
                $user->developerProfile()->create();

                return $user;
            });
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
