<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeveloperProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Usage: `User::factory()->has(DeveloperProfile::factory())->create()` or
 * `DeveloperProfile::factory()->for($user)->create()`.
 *
 * User::factory() does not create a profile by itself: tests that need one
 * ask for it, and registration tests prove that the real flow creates it.
 *
 * @extends Factory<DeveloperProfile>
 */
class DeveloperProfileFactory extends Factory
{
    /**
     * The state of a freshly registered developer: defaults only.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'timezone' => DeveloperProfile::DEFAULT_TIMEZONE,
            'locale' => 'en',
        ];
    }

    /** Same as the default state; reads better at call sites. */
    public function minimal(): static
    {
        return $this;
    }

    /** Every editable field filled with valid example values (example.test domains). */
    public function complete(): static
    {
        return $this->state(fn (): array => [
            'display_name' => 'Ada L.',
            'bio' => 'Backend developer who enjoys clean data models.',
            'avatar_url' => 'https://images.example.test/avatars/ada.png',
            'timezone' => 'Asia/Tashkent',
            'locale' => 'uz',
            'country_code' => 'UZ',
            'city' => 'Tashkent',
            'job_title' => 'Senior Engineer',
            'company' => 'Example Corp',
            'website_url' => 'https://ada.example.test',
            'github_username' => 'ada-lovelace',
            'linkedin_url' => 'https://www.linkedin.com/in/ada-example',
            'preferred_language' => 'php',
        ]);
    }
}
