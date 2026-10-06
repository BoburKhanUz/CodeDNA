<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10: the analysis pipeline (docs/architecture/data-flow.md).
     *
     * Additive only. Existing runs keep their data:
     *
     * - analysis_runs.result_type: which analyzer result type the run asked
     *   for ("foundation", Phase 08, or "static_analysis", Phase 09). Existing
     *   rows become "foundation", the analyzer's default.
     * - A SUCCEEDED run no longer needs metrics/scoring versions: a foundation
     *   result has neither, and no result has a scoring version before
     *   Phase 11. A static_analysis run still needs its metrics version.
     * - At most one QUEUED or RUNNING run per (snapshot, result type): the
     *   database backstop of the "one logical analysis" policy (StartAnalysis).
     * - analysis_results: the verified analyzer result of a SUCCEEDED run,
     *   one row per run, immutable.
     */
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->string('result_type', 32)->default('foundation')->after('source_snapshot_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE analysis_runs
                ADD CONSTRAINT analysis_runs_result_type_valid
                    CHECK (result_type IN ('foundation', 'static_analysis')),
                DROP CONSTRAINT analysis_runs_succeeded_has_result,
                ADD CONSTRAINT analysis_runs_succeeded_has_result
                    CHECK (status <> 'SUCCEEDED' OR (
                        result_hash IS NOT NULL AND analyzer_version IS NOT NULL AND ir_version IS NOT NULL
                        AND contract_version IS NOT NULL
                        AND (result_type <> 'static_analysis' OR metrics_version IS NOT NULL)
                    ))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX analysis_runs_one_active_per_snapshot_and_type
                ON analysis_runs (source_snapshot_id, result_type)
                WHERE status IN ('QUEUED', 'RUNNING')
        SQL);

        Schema::create('analysis_results', function (Blueprint $table) {
            // One result per run; the run's ID is the result's identity.
            $table->ulid('analysis_run_id')->primary();
            $table->string('result_type', 32);
            $table->char('result_hash', 64);
            $table->string('contract_version', 32);
            $table->string('analyzer_version', 32);
            $table->string('ir_version', 32);
            $table->string('metrics_version', 32)->nullable();
            // The verified analyzer response body (no source text; see the internal contract).
            $table->jsonb('result');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('analysis_run_id')->references('id')->on('analysis_runs')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE analysis_results
                ADD CONSTRAINT analysis_results_result_type_valid
                    CHECK (result_type IN ('foundation', 'static_analysis')),
                ADD CONSTRAINT analysis_results_result_hash_sha256
                    CHECK (result_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT analysis_results_result_object
                    CHECK (jsonb_typeof(result) = 'object'),
                ADD CONSTRAINT analysis_results_metrics_for_static_analysis
                    CHECK (result_type <> 'static_analysis' OR metrics_version IS NOT NULL),
                ADD CONSTRAINT analysis_results_size_positive
                    CHECK (size_bytes > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
        DB::statement('DROP INDEX IF EXISTS analysis_runs_one_active_per_snapshot_and_type');

        // The Phase 05 constraint requires versions that foundation and
        // pre-scoring runs never have; rolling back with such runs present
        // fails here instead of silently rewriting history.
        DB::statement(<<<'SQL'
            ALTER TABLE analysis_runs
                DROP CONSTRAINT analysis_runs_succeeded_has_result,
                ADD CONSTRAINT analysis_runs_succeeded_has_result
                    CHECK (status <> 'SUCCEEDED' OR (
                        result_hash IS NOT NULL AND analyzer_version IS NOT NULL AND ir_version IS NOT NULL
                        AND metrics_version IS NOT NULL AND scoring_version IS NOT NULL AND contract_version IS NOT NULL
                    )),
                DROP CONSTRAINT analysis_runs_result_type_valid
        SQL);

        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropColumn('result_type');
        });
    }
};
