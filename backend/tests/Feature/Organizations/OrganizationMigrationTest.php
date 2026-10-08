<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Billing\QuotaKey;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\UsageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BillingFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Phase 24: the organization migration is additive and safe for existing
 * data. Existing projects stay personal, users and billing are unchanged,
 * and the migration rolls back only while no organization exists. Rows are
 * committed (migrations cannot run inside RefreshDatabase's transaction).
 */
final class OrganizationMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_18_000001_create_organization_tables.php';

    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate');
        OrganizationFixtures::forget(DB::table('organizations')->whereIn('owner_user_id', $this->users)->pluck('id')->all());
        DB::table('projects')->whereIn('user_id', $this->users)->delete();
        BillingFixtures::forget($this->users);
        DB::table('users')->whereIn('id', $this->users)->delete();
        parent::tearDown();
    }

    public function test_existing_projects_users_and_billing_are_unchanged_by_the_migration(): void
    {
        $this->artisan('migrate:reset', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('projects', 'organization_id'));
        $user = User::factory()->create();
        $this->users[] = $user->id;
        $project = Project::factory()->for($user)->create(['slug' => 'existing']);
        DB::transaction(fn () => app(UsageService::class)->consume($user, QuotaKey::Analyses, 'analysis_run', strtolower((string) Str::ulid())));
        $usage = DB::table('billing_usage_events')->where('user_id', $user->id)->get();

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertNull(Project::query()->findOrFail($project->id)->organization_id, 'existing projects stay personal');
        $this->asUser($user)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.id', $project->id)->assertJsonPath('data.0.organization_id', null);
        $this->asUser($user)->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertEquals($usage, DB::table('billing_usage_events')->where('user_id', $user->id)->get()->map(fn ($e) => (object) collect($e)->except('organization_id')->all()));
        $this->asUser($user)->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.plan.key', 'FREE');
        // Personal slugs are still unique per owner.
        $this->assertThrows(fn () => DB::transaction(fn () => Project::factory()->for($user)->create(['slug' => 'existing'])), QueryException::class);
    }

    public function test_the_migration_is_not_rolled_back_over_existing_organizations(): void
    {
        $owner = User::factory()->create();
        $this->users[] = $owner->id;
        OrganizationFixtures::create($owner);

        $this->assertThrows(fn () => $this->artisan('migrate:reset', ['--path' => self::MIGRATION]), RuntimeException::class);
        $this->assertTrue(Schema::hasTable('organizations'));
    }
}
