<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\SupportedLocale;
use App\Models\DeveloperProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeveloperProfileModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_one_profile_and_a_profile_belongs_to_a_user(): void
    {
        $user = User::factory()->has(DeveloperProfile::factory())->create();
        $profile = $user->developerProfile()->sole();

        $this->assertTrue($profile->user->is($user));
        $this->assertTrue($user->developerProfile->is($profile));
        $this->assertTrue(Str::isUlid($profile->id));
    }

    public function test_a_new_profile_has_minimal_defaults(): void
    {
        $profile = User::factory()->create()->developerProfile()->create()->refresh();

        $this->assertSame('UTC', $profile->timezone);
        $this->assertSame(SupportedLocale::English, $profile->locale);
        foreach (['display_name', 'bio', 'avatar_url', 'country_code', 'city', 'job_title', 'company',
            'website_url', 'github_username', 'linkedin_url', 'preferred_language', 'metadata'] as $field) {
            $this->assertNull($profile->{$field}, "{$field} defaults to null");
        }
    }

    public function test_a_user_has_at_most_one_profile(): void
    {
        $user = User::factory()->has(DeveloperProfile::factory())->create();

        $this->expectException(UniqueConstraintViolationException::class);
        DeveloperProfile::factory()->for($user)->create();
    }

    public function test_owner_and_metadata_are_not_mass_assignable(): void
    {
        foreach (['user_id' => 'someone-else', 'metadata' => ['role' => 'admin'], 'id' => 'x'] as $field => $value) {
            try {
                new DeveloperProfile(['display_name' => 'X', $field => $value]);
                $this->fail("{$field} must not be mass assignable.");
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_user_with_a_profile_cannot_be_deleted(): void
    {
        // RESTRICT, like the Phase 05 history tables: deleting accounts is a
        // future explicit purge workflow.
        $user = User::factory()->has(DeveloperProfile::factory())->create();

        $this->expectException(QueryException::class);
        $user->delete();
    }

    /**
     * Values the API never accepts must also be refused by the database,
     * whatever code path writes them.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidRows(): array
    {
        return [
            'blank display name' => [['display_name' => '  ']],
            'bio too long' => [['bio' => str_repeat('a', 1001)]],
            'http avatar' => [['avatar_url' => 'http://example.test/a.png']],
            'javascript website' => [['website_url' => 'javascript:alert(1)']],
            'linkedin with spaces' => [['linkedin_url' => 'https://x y']],
            'timezone offset with space' => [['timezone' => 'UTC +5']],
            'locale format' => [['locale' => 'english']],
            'lowercase country' => [['country_code' => 'uz']],
            'github format' => [['github_username' => '-ada']],
            'preferred language format' => [['preferred_language' => 'Python 3']],
            'metadata list' => [['metadata' => '[1, 2]']],
            'metadata too large' => [['metadata' => json_encode(['x' => str_repeat('a', 16400)])]],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[DataProvider('invalidRows')]
    public function test_check_constraints_reject_invalid_rows(array $values): void
    {
        $profile = DeveloperProfile::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('developer_profiles')->where('id', $profile->id)->update($values);
    }

    public function test_the_complete_factory_state_satisfies_every_constraint(): void
    {
        $profile = DeveloperProfile::factory()->complete()->create()->refresh();

        $this->assertSame('Asia/Tashkent', $profile->timezone);
        $this->assertSame(SupportedLocale::Uzbek, $profile->locale);
    }
}
