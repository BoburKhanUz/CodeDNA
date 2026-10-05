<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use SensitiveParameter;

/**
 * Verifies credentials and starts an authenticated session.
 *
 * Unknown email and wrong password produce the same error, and Laravel's
 * session guard runs credential checks in a fixed-duration timebox, so the
 * response does not reveal whether an account exists.
 */
final readonly class AuthenticateUser
{
    public function __construct(private AuthFactory $auth) {}

    public function handle(string $email, #[SensitiveParameter] string $password, Session $session): User
    {
        $guard = $this->auth->guard('web');

        if (! $guard->attempt(['email' => $email, 'password' => $password])) {
            throw new ApiException(ErrorCode::InvalidCredentials);
        }

        $session->regenerate();

        /** @var User $user */
        $user = $guard->user();

        return $user;
    }
}
