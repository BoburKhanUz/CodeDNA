<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One execution of the analysis pipeline against one source snapshot
     * (docs/architecture/data-model.md#analysis-runs). A snapshot may have
     * many runs (e.g. under different analyzer versions). Terminal runs are
     * historical records.
     */
    public function up(): void
    {
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->ulid('source_snapshot_id');
            $table->string('status', 32)->default('QUEUED');
            $table->string('analyzer_version', 32)->nullable();
            $table->string('ir_version', 32)->nullable();
            $table->string('metrics_version', 32)->nullable();
            $table->string('scoring_version', 32)->nullable();
            $table->string('contract_version', 32)->nullable();
            // Sent to the analyzer as Idempotency-Key (ADR-005); set to the run ID.
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            // When the run reached a terminal status (SUCCEEDED, FAILED or CANCELLED).
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            // Contract error vocabulary (UPPER_SNAKE_CASE) and a user-safe message only.
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // The snapshot must belong to the same project as the run.
            $table->foreign(['source_snapshot_id', 'project_id'])
                ->references(['id', 'project_id'])->on('source_snapshots')
                ->restrictOnDelete();

            // Runs of a snapshot.
            $table->index('source_snapshot_id');
            // Run history of a project, newest first.
            $table->index(['project_id', 'created_at']);
            // Target of dna_snapshots' composite foreign key (analysis_run_id, project_id).
            $table->unique(['id', 'project_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE analysis_runs
                ADD CONSTRAINT analysis_runs_status_valid
                    CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELLED')),
                ADD CONSTRAINT analysis_runs_queued_not_started
                    CHECK (status <> 'QUEUED' OR started_at IS NULL),
                ADD CONSTRAINT analysis_runs_started_when_running_or_succeeded
                    CHECK (status NOT IN ('RUNNING', 'SUCCEEDED') OR started_at IS NOT NULL),
                ADD CONSTRAINT analysis_runs_completed_iff_terminal
                    CHECK ((status IN ('SUCCEEDED', 'FAILED', 'CANCELLED')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT analysis_runs_failed_at_iff_failed
                    CHECK ((status = 'FAILED') = (failed_at IS NOT NULL)),
                ADD CONSTRAINT analysis_runs_failure_code_iff_failed
                    CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
                ADD CONSTRAINT analysis_runs_failure_message_only_when_failed
                    CHECK (failure_message IS NULL OR status = 'FAILED'),
                ADD CONSTRAINT analysis_runs_failure_code_format
                    CHECK (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]*$'),
                ADD CONSTRAINT analysis_runs_result_hash_only_when_succeeded
                    CHECK (result_hash IS NULL OR status = 'SUCCEEDED'),
                ADD CONSTRAINT analysis_runs_succeeded_has_result
                    CHECK (status <> 'SUCCEEDED' OR (
                        result_hash IS NOT NULL AND analyzer_version IS NOT NULL AND ir_version IS NOT NULL
                        AND metrics_version IS NOT NULL AND scoring_version IS NOT NULL AND contract_version IS NOT NULL
                    )),
                ADD CONSTRAINT analysis_runs_result_hash_sha256
                    CHECK (result_hash IS NULL OR result_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT analysis_runs_completed_after_started
                    CHECK (completed_at IS NULL OR started_at IS NULL OR completed_at >= started_at),
                ADD CONSTRAINT analysis_runs_metadata_object
                    CHECK (metadata IS NULL OR (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 16384))
        SQL);

        // Idempotency keys identify analyzer requests globally (ADR-005).
        DB::statement('CREATE UNIQUE INDEX analysis_runs_idempotency_key_unique ON analysis_runs (idempotency_key) WHERE idempotency_key IS NOT NULL');
        // Small index for the stale-run sweeper and "in progress" lookups.
        DB::statement("CREATE INDEX analysis_runs_active_idx ON analysis_runs (status, updated_at) WHERE status IN ('QUEUED', 'RUNNING')");
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};
