<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_may_view_only_their_own_account(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create()->all();

        $this->assertTrue(Gate::forUser($alice)->allows('view', $alice));
        $this->assertFalse(Gate::forUser($alice)->allows('view', $bob));
    }
}
