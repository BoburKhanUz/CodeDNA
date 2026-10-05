<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;

/**
 * Ends the session: logs out, invalidates the session data and ID, and
 * rotates the CSRF token.
 */
final readonly class LogoutUser
{
    public function __construct(private AuthFactory $auth) {}

    public function handle(Session $session): void
    {
        $this->auth->guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }
}
