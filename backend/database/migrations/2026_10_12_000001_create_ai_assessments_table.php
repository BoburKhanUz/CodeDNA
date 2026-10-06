<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 15, AI assessment and interpretation
     * (docs/architecture/ai-assessment-v1.md): ai_assessments holds one row
     * per requested assessment, with full lineage, every version and
     * fingerprint, the provider and model, the canonical input, the
     * validated output and the outcome.
     *
     * - Lineage is one composite foreign key onto a new unique index on
     *   skill_gap_snapshots (an index only: no row changes).
     * - A partial unique index allows one QUEUED, RUNNING or SUCCEEDED
     *   assessment per identity (project, input, version, prompt, provider,
     *   model); a FAILED one never blocks a new attempt.
     * - A trigger refuses any update of a SUCCEEDED or FAILED row: terminal
     *   records are immutable in the database, not only in the model.
     *
     * No API keys, headers, prompts or source are stored. No cascades.
     */
    public function up(): void
    {
        Schema::table('skill_gap_snapshots', function (Blueprint $table) {
            $table->unique(
                ['id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'],
                'skill_gap_snapshots_lineage_unique',
            );
        });

        Schema::create('ai_assessments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->ulid('skill_gap_snapshot_id');
            $table->ulid('competency_snapshot_id');
            $table->ulid('dna_snapshot_id');
            $table->ulid('analysis_run_id');
            $table->ulid('source_snapshot_id');
            $table->string('assessment_version', 32);
            $table->string('input_schema_version', 32);
            $table->string('output_schema_version', 32);
            $table->string('prompt_version', 32);
            $table->char('prompt_fingerprint', 64);
            $table->char('specification_fingerprint', 64);
            $table->char('input_fingerprint', 64);
            $table->string('dna_scoring_version', 32);
            $table->string('competency_version', 32);
            $table->string('skill_gap_version', 32);
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            // Lease of the worker currently processing the row (see the job).
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            // {lineage, payload}: the payload is exactly what the provider received.
            $table->jsonb('input');
            $table->jsonb('output')->nullable();
            $table->char('output_fingerprint', 64)->nullable();
            // Served model, response id and token usage only.
            $table->jsonb('provider_metadata')->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_detail', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['skill_gap_snapshot_id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'],
                'ai_assessments_lineage_foreign',
            )->references(['id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'])
                ->on('skill_gap_snapshots')
                ->restrictOnDelete();

            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_assessments
                ADD CONSTRAINT ai_assessments_status_valid CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED')),
                ADD CONSTRAINT ai_assessments_fingerprints_sha256 CHECK (
                    prompt_fingerprint ~ '^[0-9a-f]{64}$'
                    AND specification_fingerprint ~ '^[0-9a-f]{64}$'
                    AND input_fingerprint ~ '^[0-9a-f]{64}$'
                    AND (output_fingerprint IS NULL OR output_fingerprint ~ '^[0-9a-f]{64}$')),
                ADD CONSTRAINT ai_assessments_provider_format CHECK (provider ~ '^[a-z_]{1,32}$' AND model ~ '^[A-Za-z0-9._:/-]{1,128}$'),
                ADD CONSTRAINT ai_assessments_attempts_range CHECK (attempts BETWEEN 0 AND 10),
                ADD CONSTRAINT ai_assessments_lease_iff_running CHECK (
                    (status = 'RUNNING') = (claim_token IS NOT NULL) AND (claim_token IS NULL) = (lease_expires_at IS NULL)),
                ADD CONSTRAINT ai_assessments_output_iff_succeeded CHECK (
                    (status = 'SUCCEEDED') = (output IS NOT NULL AND output_fingerprint IS NOT NULL)),
                ADD CONSTRAINT ai_assessments_failure_iff_failed CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
                ADD CONSTRAINT ai_assessments_failure_format CHECK (
                    (failure_code IS NULL OR failure_code ~ '^[A-Z_]{1,32}$')
                    AND (failure_detail IS NULL OR (failure_code IS NOT NULL AND failure_detail ~ '^[a-z0-9_]{1,64}$'))),
                ADD CONSTRAINT ai_assessments_completed_iff_terminal CHECK (
                    (status IN ('SUCCEEDED', 'FAILED')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT ai_assessments_input_object CHECK (jsonb_typeof(input) = 'object' AND octet_length(input::text) <= 524288),
                ADD CONSTRAINT ai_assessments_output_object CHECK (
                    output IS NULL OR (jsonb_typeof(output) = 'object' AND octet_length(output::text) <= 262144)),
                ADD CONSTRAINT ai_assessments_provider_metadata_object CHECK (
                    provider_metadata IS NULL OR (jsonb_typeof(provider_metadata) = 'object' AND octet_length(provider_metadata::text) <= 4096))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_assessments_identity_active_unique
                ON ai_assessments (project_id, input_fingerprint, assessment_version, prompt_fingerprint, provider, model)
                WHERE status IN ('QUEUED', 'RUNNING', 'SUCCEEDED')
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_assessments_refuse_terminal_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ai_assessments: a % assessment is immutable', OLD.status USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ai_assessments_terminal_immutable
                BEFORE UPDATE ON ai_assessments
                FOR EACH ROW
                WHEN (OLD.status IN ('SUCCEEDED', 'FAILED'))
                EXECUTE FUNCTION ai_assessments_refuse_terminal_update();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assessments');
        DB::unprepared('DROP FUNCTION IF EXISTS ai_assessments_refuse_terminal_update()');

        Schema::table('skill_gap_snapshots', function (Blueprint $table) {
            $table->dropUnique('skill_gap_snapshots_lineage_unique');
        });
    }
};
