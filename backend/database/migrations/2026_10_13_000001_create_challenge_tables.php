<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 16, coding challenges (docs/architecture/coding-challenges-v1.md):
     *
     * - challenge_definitions: published catalog definitions as used, one
     *   row per (key, version), never updated (trigger);
     * - challenge_instances: one challenge assigned for one skill gap
     *   snapshot, with the selection provenance; lineage is one composite
     *   foreign key onto skill_gap_snapshots_lineage_unique (Phase 15);
     * - challenge_submissions: one immutable row per attempt, with its
     *   source, fingerprints and evaluation.
     *
     * A practice layer only: nothing here references a score, and nothing
     * writes to any analysis, DNA, competency or skill gap table. No
     * cascades. Terminal instances and submissions are immutable in the
     * database (triggers), not only in the models.
     */
    public function up(): void
    {
        Schema::create('challenge_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 64);
            $table->string('version', 32);
            $table->string('catalog_version', 32);
            $table->string('category', 32);
            $table->string('difficulty', 16);
            $table->string('language', 16);
            $table->string('runtime', 32);
            $table->string('title', 120);
            $table->char('definition_fingerprint', 64);
            $table->char('test_suite_fingerprint', 64);
            // The full definition, hidden cases included: server-side only.
            $table->jsonb('document');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['key', 'version']);
            // Target of the instances' definition key.
            $table->unique(['id', 'key', 'version']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE challenge_definitions
                ADD CONSTRAINT challenge_definitions_category_valid
                    CHECK (category IN ('COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE')),
                ADD CONSTRAINT challenge_definitions_key_in_category CHECK (key ~ ('^' || category || '_[0-9]{3}$')),
                ADD CONSTRAINT challenge_definitions_difficulty_valid CHECK (difficulty IN ('BEGINNER', 'INTERMEDIATE', 'ADVANCED')),
                ADD CONSTRAINT challenge_definitions_language_executable CHECK (language = 'python' AND runtime = 'python3.11'),
                ADD CONSTRAINT challenge_definitions_version_format CHECK (version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
                ADD CONSTRAINT challenge_definitions_fingerprints_sha256
                    CHECK (definition_fingerprint ~ '^[0-9a-f]{64}$' AND test_suite_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT challenge_definitions_document_object
                    CHECK (jsonb_typeof(document) = 'object' AND octet_length(document::text) <= 262144)
        SQL);

        Schema::create('challenge_instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->ulid('skill_gap_snapshot_id');
            $table->ulid('competency_snapshot_id');
            $table->ulid('dna_snapshot_id');
            $table->ulid('analysis_run_id');
            $table->ulid('source_snapshot_id');
            $table->ulid('challenge_definition_id');
            $table->string('definition_key', 64);
            $table->string('definition_version', 32);
            $table->string('competency_key', 64);
            $table->string('difficulty', 16);
            $table->string('language', 16);
            $table->string('selection_version', 64);
            $table->string('catalog_version', 32);
            $table->char('catalog_fingerprint', 64);
            // Why this challenge was selected (deterministic provenance).
            $table->jsonb('selection');
            $table->string('status', 16);
            $table->unsignedSmallInteger('max_attempts');
            // Graded attempts (PASSED or FAILED submissions); errors do not count.
            $table->unsignedSmallInteger('attempts_used')->default(0);
            $table->string('last_result', 16)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['skill_gap_snapshot_id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'],
                'challenge_instances_lineage_foreign',
            )->references(['id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'])
                ->on('skill_gap_snapshots')
                ->restrictOnDelete();
            $table->foreign(['challenge_definition_id', 'definition_key', 'definition_version'], 'challenge_instances_definition_foreign')
                ->references(['id', 'key', 'version'])->on('challenge_definitions')
                ->restrictOnDelete();

            // A definition is assigned at most once per skill gap snapshot.
            $table->unique(['skill_gap_snapshot_id', 'definition_key'], 'challenge_instances_definition_per_snapshot_unique');
            // Target of the submissions' composite foreign key.
            $table->unique(['id', 'project_id', 'user_id'], 'challenge_instances_owner_unique');
            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE challenge_instances
                ADD CONSTRAINT challenge_instances_status_valid CHECK (status IN ('ASSIGNED', 'EVALUATING', 'PASSED', 'FAILED')),
                ADD CONSTRAINT challenge_instances_definition_matches_gap CHECK (definition_key LIKE competency_key || '\_%'),
                ADD CONSTRAINT challenge_instances_attempts_range CHECK (max_attempts BETWEEN 1 AND 20 AND attempts_used BETWEEN 0 AND max_attempts),
                ADD CONSTRAINT challenge_instances_failed_iff_exhausted CHECK (
                    (status = 'FAILED') = (attempts_used = max_attempts AND last_result IS NOT DISTINCT FROM 'FAILED')),
                ADD CONSTRAINT challenge_instances_passed_result CHECK (status <> 'PASSED' OR last_result IS NOT DISTINCT FROM 'PASSED'),
                ADD CONSTRAINT challenge_instances_last_result_valid CHECK (last_result IS NULL OR last_result IN ('PASSED', 'FAILED', 'ERROR')),
                ADD CONSTRAINT challenge_instances_closed_iff_terminal CHECK ((status IN ('PASSED', 'FAILED')) = (closed_at IS NOT NULL)),
                ADD CONSTRAINT challenge_instances_difficulty_valid CHECK (difficulty IN ('BEGINNER', 'INTERMEDIATE', 'ADVANCED')),
                ADD CONSTRAINT challenge_instances_fingerprint_sha256 CHECK (catalog_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT challenge_instances_selection_object
                    CHECK (jsonb_typeof(selection) = 'object' AND octet_length(selection::text) <= 16384)
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX challenge_instances_active_per_gap_unique
                ON challenge_instances (project_id, skill_gap_snapshot_id, competency_key)
                WHERE status IN ('ASSIGNED', 'EVALUATING')
        SQL);

        Schema::create('challenge_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('challenge_instance_id');
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->ulid('challenge_definition_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('language', 16);
            $table->text('source');
            $table->char('source_sha256', 64);
            $table->unsignedInteger('source_bytes');
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->string('status', 16);
            $table->char('definition_fingerprint', 64);
            $table->char('test_suite_fingerprint', 64);
            $table->string('evaluation_version', 64);
            $table->string('evaluator', 32);
            $table->string('evaluator_version', 32)->nullable();
            $table->string('runtime', 32)->nullable();
            $table->string('execution_status', 16)->nullable();
            $table->jsonb('evaluation')->nullable();
            $table->char('evaluation_fingerprint', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_detail', 64)->nullable();
            $table->unsignedSmallInteger('job_attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['challenge_instance_id', 'project_id', 'user_id'], 'challenge_submissions_instance_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('challenge_instances')
                ->restrictOnDelete();
            $table->foreign('challenge_definition_id')->references('id')->on('challenge_definitions')->restrictOnDelete();

            $table->unique(['challenge_instance_id', 'attempt_number'], 'challenge_submissions_attempt_unique');
            $table->unique(['challenge_instance_id', 'idempotency_key_hash'], 'challenge_submissions_idempotency_unique');
            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE challenge_submissions
                ADD CONSTRAINT challenge_submissions_status_valid CHECK (status IN ('QUEUED', 'RUNNING', 'PASSED', 'FAILED', 'ERROR')),
                ADD CONSTRAINT challenge_submissions_attempt_range CHECK (attempt_number BETWEEN 1 AND 100 AND job_attempts BETWEEN 0 AND 10),
                ADD CONSTRAINT challenge_submissions_language_valid CHECK (language = 'python'),
                ADD CONSTRAINT challenge_submissions_source_bounded CHECK (
                    source_bytes = octet_length(source) AND source_bytes BETWEEN 1 AND 65536
                    AND source_sha256 = encode(sha256(convert_to(source, 'UTF8')), 'hex')),
                ADD CONSTRAINT challenge_submissions_fingerprints_sha256 CHECK (
                    definition_fingerprint ~ '^[0-9a-f]{64}$' AND test_suite_fingerprint ~ '^[0-9a-f]{64}$'
                    AND (evaluation_fingerprint IS NULL OR evaluation_fingerprint ~ '^[0-9a-f]{64}$')
                    AND (idempotency_key_hash IS NULL OR idempotency_key_hash ~ '^[0-9a-f]{64}$')),
                ADD CONSTRAINT challenge_submissions_evaluation_iff_graded CHECK (
                    (status IN ('PASSED', 'FAILED')) = (evaluation IS NOT NULL AND evaluation_fingerprint IS NOT NULL AND execution_status IS NOT NULL)),
                ADD CONSTRAINT challenge_submissions_failure_iff_error CHECK ((status = 'ERROR') = (failure_code IS NOT NULL)),
                ADD CONSTRAINT challenge_submissions_failure_format CHECK (
                    (failure_code IS NULL OR failure_code ~ '^[A-Z_]{1,32}$')
                    AND (failure_detail IS NULL OR (failure_code IS NOT NULL AND failure_detail ~ '^[a-z0-9_]{1,64}$'))),
                ADD CONSTRAINT challenge_submissions_completed_iff_terminal CHECK (
                    (status IN ('PASSED', 'FAILED', 'ERROR')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT challenge_submissions_lease_iff_running CHECK (
                    (status = 'RUNNING') = (claim_token IS NOT NULL) AND (claim_token IS NULL) = (lease_expires_at IS NULL)),
                ADD CONSTRAINT challenge_submissions_evaluation_object CHECK (
                    evaluation IS NULL OR (jsonb_typeof(evaluation) = 'object' AND octet_length(evaluation::text) <= 131072))
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX challenge_submissions_one_pending_unique
                ON challenge_submissions (challenge_instance_id)
                WHERE status IN ('QUEUED', 'RUNNING')
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION challenge_definitions_refuse_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'challenge_definitions: a published definition is immutable' USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER challenge_definitions_immutable
                BEFORE UPDATE ON challenge_definitions
                FOR EACH ROW EXECUTE FUNCTION challenge_definitions_refuse_update();

            CREATE OR REPLACE FUNCTION challenge_instances_guard_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('PASSED', 'FAILED') THEN
                    RAISE EXCEPTION 'challenge_instances: a % challenge is immutable', OLD.status USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.user_id, NEW.project_id, NEW.skill_gap_snapshot_id, NEW.challenge_definition_id, NEW.definition_key,
                    NEW.definition_version, NEW.competency_key, NEW.difficulty, NEW.language, NEW.selection, NEW.selection_version,
                    NEW.catalog_version, NEW.catalog_fingerprint, NEW.max_attempts, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.user_id, OLD.project_id, OLD.skill_gap_snapshot_id, OLD.challenge_definition_id, OLD.definition_key,
                    OLD.definition_version, OLD.competency_key, OLD.difficulty, OLD.language, OLD.selection, OLD.selection_version,
                    OLD.catalog_version, OLD.catalog_fingerprint, OLD.max_attempts, OLD.created_at) THEN
                    RAISE EXCEPTION 'challenge_instances: identity and provenance are immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.attempts_used < OLD.attempts_used THEN
                    RAISE EXCEPTION 'challenge_instances: attempts are never given back' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER challenge_instances_guarded
                BEFORE UPDATE ON challenge_instances
                FOR EACH ROW EXECUTE FUNCTION challenge_instances_guard_update();

            CREATE OR REPLACE FUNCTION challenge_submissions_guard() RETURNS trigger AS $$
            DECLARE
                instance challenge_instances%ROWTYPE;
                graded integer;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO instance FROM challenge_instances WHERE id = NEW.challenge_instance_id;
                    IF instance.status <> 'ASSIGNED' THEN
                        RAISE EXCEPTION 'challenge_submissions: the challenge does not accept submissions (%)', instance.status
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.challenge_definition_id <> instance.challenge_definition_id OR NEW.language <> instance.language THEN
                        RAISE EXCEPTION 'challenge_submissions: definition or language differs from the challenge' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT count(*) INTO graded FROM challenge_submissions
                        WHERE challenge_instance_id = NEW.challenge_instance_id AND status IN ('PASSED', 'FAILED');
                    IF graded >= instance.max_attempts THEN
                        RAISE EXCEPTION 'challenge_submissions: no attempts left' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status IN ('PASSED', 'FAILED', 'ERROR') THEN
                    RAISE EXCEPTION 'challenge_submissions: a % submission is immutable', OLD.status USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.challenge_instance_id, NEW.project_id, NEW.user_id, NEW.challenge_definition_id, NEW.attempt_number, NEW.language,
                    NEW.source, NEW.source_sha256, NEW.source_bytes, NEW.idempotency_key_hash, NEW.definition_fingerprint,
                    NEW.test_suite_fingerprint, NEW.evaluation_version, NEW.evaluator, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.challenge_instance_id, OLD.project_id, OLD.user_id, OLD.challenge_definition_id, OLD.attempt_number, OLD.language,
                    OLD.source, OLD.source_sha256, OLD.source_bytes, OLD.idempotency_key_hash, OLD.definition_fingerprint,
                    OLD.test_suite_fingerprint, OLD.evaluation_version, OLD.evaluator, OLD.created_at) THEN
                    RAISE EXCEPTION 'challenge_submissions: a submission and its source are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER challenge_submissions_guarded
                BEFORE INSERT OR UPDATE ON challenge_submissions
                FOR EACH ROW EXECUTE FUNCTION challenge_submissions_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_submissions');
        Schema::dropIfExists('challenge_instances');
        Schema::dropIfExists('challenge_definitions');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS challenge_submissions_guard();
            DROP FUNCTION IF EXISTS challenge_instances_guard_update();
            DROP FUNCTION IF EXISTS challenge_definitions_refuse_update();
        SQL);
    }
};
