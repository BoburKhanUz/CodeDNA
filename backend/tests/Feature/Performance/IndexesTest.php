<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Indexes that the Phase 26 measurements depend on
 * (docs/performance/database-performance.md#indexes). Dropping or reshaping
 * one fails here, not silently as a slower history page.
 */
final class IndexesTest extends TestCase
{
    use RefreshDatabase;

    private function definition(string $index): string
    {
        return (string) DB::scalar('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$index]);
    }

    public function test_a_projects_successful_analyses_are_indexed_in_history_order(): void
    {
        $this->assertSame(
            "CREATE INDEX analysis_runs_project_history_idx ON public.analysis_runs USING btree (project_id, completed_at DESC, id DESC) WHERE ((status)::text = 'SUCCEEDED'::text)",
            $this->definition('analysis_runs_project_history_idx'),
        );
    }

    public function test_the_keyset_and_latest_snapshot_reads_have_their_owner_indexes(): void
    {
        // Audit pages walk (organization_id, created_at, id); the latest
        // snapshot per project is one probe of (project_id, created_at).
        $this->assertStringContainsString('(organization_id, created_at, id)', $this->definition('organization_audit_events_organization_id_created_at_id_index'));
        foreach (['dna_snapshots', 'skill_gap_snapshots', 'competency_snapshots', 'growth_snapshots'] as $table) {
            $column = $table === 'growth_snapshots' ? 'assessed_at' : 'created_at';
            $this->assertStringContainsString("(project_id, {$column})", $this->definition("{$table}_project_id_{$column}_index"), $table);
        }
        $this->assertStringContainsString('(project_id, created_at)', $this->definition('analysis_runs_project_id_created_at_index'));
        $this->assertStringContainsString('(user_id, created_at)', $this->definition('billing_usage_events_user_id_created_at_index'));
    }
}
