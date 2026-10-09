<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 29, local AI intelligence (docs/architecture/ai-intelligence-v1.md):
     * ai_insights holds one row per requested evidence-grounded interpretation
     * of a growth snapshot, a learning roadmap or an evaluated challenge
     * submission, with its subject, versions, fingerprints, provider and
     * model, the canonical input, the validated output and the outcome.
     *
     * - Exactly one subject column is set, and it matches the kind. Each
     *   subject is reached through a composite foreign key that includes the
     *   project and the owner, so an insight can never point at another
     *   tenant's data. challenge_submissions gets the unique index this needs
     *   (an index only: no row changes).
     * - A partial unique index allows one QUEUED, RUNNING or SUCCEEDED insight
     *   per identity; a FAILED one never blocks a new request.
     * - A trigger refuses any update of a SUCCEEDED or FAILED row.
     *
     * No API keys, headers, prompts or source code are stored. No cascades.
     * down() drops only what this migration created.
     */
    public function up(): void
    {
        Schema::table('challenge_submissions', function (Blueprint $table) {
            $table->unique(['id', 'project_id', 'user_id'], 'challenge_submissions_owner_unique');
        });

        Schema::create('ai_insights', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->string('kind', 32);
            $table->ulid('growth_snapshot_id')->nullable();
            $table->ulid('roadmap_snapshot_id')->nullable();
            $table->ulid('challenge_submission_id')->nullable();
            $table->ulid('requested_by');
            $table->string('insight_version', 32);
            $table->string('input_schema_version', 32);
            $table->string('output_schema_version', 32);
            $table->string('prompt_version', 32);
            $table->char('prompt_fingerprint', 64);
            $table->char('specification_fingerprint', 64);
            $table->char('input_fingerprint', 64);
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            // {lineage, payload}: the payload is exactly what the model received.
            $table->jsonb('input');
            $table->jsonb('output')->nullable();
            $table->char('output_fingerprint', 64)->nullable();
            // Served model, token counts (when reported) and duration only.
            $table->jsonb('provider_metadata')->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_detail', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['growth_snapshot_id', 'project_id', 'user_id'], 'ai_insights_growth_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('growth_snapshots')->restrictOnDelete();
            $table->foreign(['roadmap_snapshot_id', 'project_id', 'user_id'], 'ai_insights_roadmap_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('roadmap_snapshots')->restrictOnDelete();
            $table->foreign(['challenge_submission_id', 'project_id', 'user_id'], 'ai_insights_challenge_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('challenge_submissions')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();

            $table->index(['project_id', 'kind', 'created_at']);
            $table->index(['status', 'updated_at']);
            $table->index('growth_snapshot_id');
            $table->index('roadmap_snapshot_id');
            $table->index('challenge_submission_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_insights
                ADD CONSTRAINT ai_insights_kind_valid CHECK (kind IN ('GROWTH_INTERPRETATION', 'ROADMAP_GUIDANCE', 'CHALLENGE_FEEDBACK')),
                ADD CONSTRAINT ai_insights_one_subject CHECK (
                    (kind = 'GROWTH_INTERPRETATION') = (growth_snapshot_id IS NOT NULL)
                    AND (kind = 'ROADMAP_GUIDANCE') = (roadmap_snapshot_id IS NOT NULL)
                    AND (kind = 'CHALLENGE_FEEDBACK') = (challenge_submission_id IS NOT NULL)),
                ADD CONSTRAINT ai_insights_status_valid CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED')),
                ADD CONSTRAINT ai_insights_fingerprints_sha256 CHECK (
                    prompt_fingerprint ~ '^[0-9a-f]{64}$'
                    AND specification_fingerprint ~ '^[0-9a-f]{64}$'
                    AND input_fingerprint ~ '^[0-9a-f]{64}$'
                    AND (output_fingerprint IS NULL OR output_fingerprint ~ '^[0-9a-f]{64}$')),
                ADD CONSTRAINT ai_insights_provider_format CHECK (provider ~ '^[a-z_]{1,32}$' AND model ~ '^[A-Za-z0-9._:/-]{1,128}$'),
                ADD CONSTRAINT ai_insights_attempts_range CHECK (attempts BETWEEN 0 AND 10),
                ADD CONSTRAINT ai_insights_lease_iff_running CHECK (
                    (status = 'RUNNING') = (claim_token IS NOT NULL) AND (claim_token IS NULL) = (lease_expires_at IS NULL)),
                ADD CONSTRAINT ai_insights_output_iff_succeeded CHECK (
                    (status = 'SUCCEEDED') = (output IS NOT NULL AND output_fingerprint IS NOT NULL)),
                ADD CONSTRAINT ai_insights_failure_iff_failed CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
                ADD CONSTRAINT ai_insights_failure_format CHECK (
                    (failure_code IS NULL OR failure_code ~ '^[A-Z_]{1,32}$')
                    AND (failure_detail IS NULL OR (failure_code IS NOT NULL AND failure_detail ~ '^[a-z0-9_]{1,64}$'))),
                ADD CONSTRAINT ai_insights_completed_iff_terminal CHECK (
                    (status IN ('SUCCEEDED', 'FAILED')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT ai_insights_input_object CHECK (jsonb_typeof(input) = 'object' AND octet_length(input::text) <= 524288),
                ADD CONSTRAINT ai_insights_output_object CHECK (
                    output IS NULL OR (jsonb_typeof(output) = 'object' AND octet_length(output::text) <= 262144)),
                ADD CONSTRAINT ai_insights_provider_metadata_object CHECK (
                    provider_metadata IS NULL OR (jsonb_typeof(provider_metadata) = 'object' AND octet_length(provider_metadata::text) <= 4096))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_insights_identity_active_unique
                ON ai_insights (project_id, kind, input_fingerprint, insight_version, prompt_fingerprint, provider, model)
                WHERE status IN ('QUEUED', 'RUNNING', 'SUCCEEDED')
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_insights_refuse_terminal_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ai_insights: a % insight is immutable', OLD.status USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ai_insights_terminal_immutable
                BEFORE UPDATE ON ai_insights
                FOR EACH ROW
                WHEN (OLD.status IN ('SUCCEEDED', 'FAILED'))
                EXECUTE FUNCTION ai_insights_refuse_terminal_update();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
        DB::unprepared('DROP FUNCTION IF EXISTS ai_insights_refuse_terminal_update()');

        Schema::table('challenge_submissions', function (Blueprint $table) {
            $table->dropUnique('challenge_submissions_owner_unique');
        });
    }
};
