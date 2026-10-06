<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable DNA result of one successful analysis run
     * (docs/architecture/data-model.md#dna-snapshots, ADR-004). A new
     * analysis creates a new snapshot; old snapshots are never changed.
     * No updated_at: rows are never updated.
     */
    public function up(): void
    {
        Schema::create('dna_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            // At most one DNA snapshot per run.
            $table->ulid('analysis_run_id')->unique();
            $table->string('analyzer_version', 32)->nullable();
            $table->string('ir_version', 32)->nullable();
            $table->string('metrics_version', 32)->nullable();
            $table->string('scoring_version', 32);
            $table->string('contract_version', 32)->nullable();
            $table->string('status', 32);
            // 0–1 scale, 4 decimal places (ADR-004); null unless READY.
            $table->decimal('overall_score', 5, 4)->nullable();
            $table->jsonb('dimensions');
            $table->jsonb('competencies')->nullable();
            $table->jsonb('strengths')->nullable();
            $table->jsonb('weaknesses')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->char('result_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            // The run must belong to the same project...
            $table->foreign(['analysis_run_id', 'project_id'])
                ->references(['id', 'project_id'])->on('analysis_runs')
                ->restrictOnDelete();
            // ...and the project to the same user.
            $table->foreign(['project_id', 'user_id'])
                ->references(['id', 'user_id'])->on('projects')
                ->restrictOnDelete();

            // A developer's DNA history and a project's DNA history, newest first.
            $table->index(['user_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE dna_snapshots
                ADD CONSTRAINT dna_snapshots_status_valid CHECK (status IN ('READY', 'INSUFFICIENT_DATA')),
                ADD CONSTRAINT dna_snapshots_score_iff_ready CHECK ((status = 'READY') = (overall_score IS NOT NULL)),
                ADD CONSTRAINT dna_snapshots_score_range CHECK (overall_score IS NULL OR overall_score BETWEEN 0 AND 1),
                ADD CONSTRAINT dna_snapshots_result_hash_sha256 CHECK (result_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT dna_snapshots_dimensions_object
                    CHECK (jsonb_typeof(dimensions) = 'object' AND octet_length(dimensions::text) <= 65536),
                ADD CONSTRAINT dna_snapshots_competencies_object
                    CHECK (competencies IS NULL OR (jsonb_typeof(competencies) = 'object' AND octet_length(competencies::text) <= 65536)),
                ADD CONSTRAINT dna_snapshots_strengths_array
                    CHECK (strengths IS NULL OR (jsonb_typeof(strengths) = 'array' AND octet_length(strengths::text) <= 65536)),
                ADD CONSTRAINT dna_snapshots_weaknesses_array
                    CHECK (weaknesses IS NULL OR (jsonb_typeof(weaknesses) = 'array' AND octet_length(weaknesses::text) <= 65536)),
                ADD CONSTRAINT dna_snapshots_evidence_object
                    CHECK (evidence IS NULL OR (jsonb_typeof(evidence) = 'object' AND octet_length(evidence::text) <= 65536))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dna_snapshots');
    }
};
