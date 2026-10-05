<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;

/**
 * Logs a user into the session-based `web` guard and regenerates the session
 * ID to prevent session fixation.
 */
final readonly class StartUserSession
{
    public function __construct(private AuthFactory $auth) {}

    public function handle(User $user, Session $session): void
    {
        $this->auth->guard('web')->login($user);
        $session->regenerate();
    }
}
