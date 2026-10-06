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

        // Framework/auth (Phase 03) + the four domain tables (Phase 05), nothing else.
        $this->assertSame([
            'analysis_runs', 'dna_snapshots', 'failed_jobs', 'migrations',
            'personal_access_tokens', 'projects', 'source_snapshots', 'users',
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
