<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_connects_to_the_dedicated_postgresql_16_test_database(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('codedna_test', DB::connection()->getDatabaseName());
        $this->assertStringStartsWith('16.', (string) DB::scalar('show server_version'));
    }

    public function test_migrations_create_only_the_expected_tables(): void
    {
        $tables = collect(DB::select(
            "select table_name from information_schema.tables where table_schema = 'public' order by table_name"
        ))->pluck('table_name')->all();

        // Framework/auth (Phase 03), the four domain tables (Phase 05),
        // developer profiles (Phase 06), analysis results (Phase 10),
        // competency snapshots (Phase 13), skill gaps (Phase 14), AI assessments
        // (Phase 15), challenges (Phase 16), roadmaps (Phase 17) growth (Phase 18), GitHub (Phase 19)
        // billing (Phase 23) and organizations (Phase 24), nothing else.
        $this->assertSame([
            'ai_assessments', 'analysis_results', 'analysis_runs',
            'billing_customers', 'billing_organization_usage_counters', 'billing_plan_features', 'billing_plan_quotas', 'billing_plans',
            'billing_subscription_events', 'billing_subscriptions', 'billing_usage_counters', 'billing_usage_events', 'billing_webhook_events',
            'challenge_definitions', 'challenge_instances', 'challenge_submissions', 'competency_snapshots', 'developer_profiles', 'dna_snapshots', 'failed_jobs', 'github_accounts', 'github_connections', 'github_imports', 'github_oauth_states', 'growth_observations', 'growth_snapshots', 'migrations',
            'organization_audit_events', 'organization_billing_accounts', 'organization_invitations', 'organization_memberships', 'organizations',
            'personal_access_tokens', 'projects', 'roadmap_snapshots', 'roadmap_step_completions', 'roadmap_steps', 'skill_gap_results', 'skill_gap_snapshots', 'source_snapshots', 'users',
        ], $tables);
    }

    public function test_transactions_commit(): void
    {
        DB::transaction(static fn () => User::factory()->create(['email' => 'commit@example.com']));

        $this->assertDatabaseHas('users', ['email' => 'commit@example.com']);
    }

    public function test_transactions_roll_back_on_failure(): void
    {
        try {
            DB::transaction(static function (): void {
                User::factory()->create(['email' => 'rollback@example.com']);
                throw new RuntimeException('abort');
            });
            $this->fail('The transaction should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('abort', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
    }
}
