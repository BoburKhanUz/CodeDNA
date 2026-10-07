<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learning roadmaps (Phase 17, docs/architecture/learning-roadmap-v1.md).
 * A planning layer: these tables are never read by, and never write to, any
 * DNA, competency or skill gap table.
 *
 * - roadmap_snapshots: one roadmap per (skill gap snapshot, roadmap version,
 *   rules version). Its content (focus, tracks, versions, fingerprints,
 *   lineage) never changes; only the status moves ACTIVE -> COMPLETED or
 *   ACTIVE -> SUPERSEDED, once (trigger).
 * - roadmap_steps: the roadmap's steps, copied from the catalog, immutable.
 * - roadmap_step_completions: self-reported progress, one insert-only row
 *   per completed step.
 *
 * Lineage reuses the skill gap snapshot's lineage index (Phase 15): the
 * roadmap, its skill gap, competency and DNA snapshots, run, source
 * snapshot, project and owner cannot disagree. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->ulid('project_id');
            $table->ulid('skill_gap_snapshot_id');
            $table->ulid('competency_snapshot_id');
            $table->ulid('dna_snapshot_id');
            $table->ulid('analysis_run_id');
            $table->ulid('source_snapshot_id');
            $table->string('roadmap_version', 32);
            $table->string('rules_version', 32);
            $table->char('catalog_fingerprint', 64);
            $table->char('rules_fingerprint', 64);
            $table->char('roadmap_fingerprint', 64);
            $table->string('skill_gap_version', 32);
            $table->char('skill_gap_specification_fingerprint', 64);
            $table->string('target_profile', 64);
            $table->string('target_profile_version', 32);
            $table->string('challenge_catalog_version', 32);
            $table->char('challenge_catalog_fingerprint', 64);
            $table->jsonb('focus');
            $table->jsonb('tracks');
            $table->unsignedSmallInteger('step_count');
            $table->unsignedInteger('estimated_minutes');
            $table->string('status', 16);
            $table->ulid('superseded_by_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['skill_gap_snapshot_id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'],
                'roadmap_snapshots_lineage_foreign',
            )->references(['id', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'user_id'])
                ->on('skill_gap_snapshots')
                ->restrictOnDelete();
            $table->unique(['skill_gap_snapshot_id', 'roadmap_version', 'rules_version'], 'roadmap_snapshots_identity_unique');
            // Targets of the steps', completions' and successor's composite foreign keys.
            $table->unique(['id', 'project_id', 'user_id'], 'roadmap_snapshots_owner_unique');
            $table->unique(['id', 'project_id'], 'roadmap_snapshots_project_unique');
            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
        // The successor is named before it is inserted (same transaction), so the check is deferred to commit.
        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_snapshots ADD CONSTRAINT roadmap_snapshots_successor_foreign
                FOREIGN KEY (superseded_by_id, project_id) REFERENCES roadmap_snapshots (id, project_id)
                ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_snapshots
                ADD CONSTRAINT roadmap_snapshots_status_valid CHECK (status IN ('ACTIVE', 'COMPLETED', 'SUPERSEDED')),
                ADD CONSTRAINT roadmap_snapshots_superseded_iff CHECK (
                    (status = 'SUPERSEDED') = (superseded_at IS NOT NULL AND superseded_by_id IS NOT NULL)
                    AND (superseded_at IS NULL) = (superseded_by_id IS NULL)
                    AND superseded_by_id IS DISTINCT FROM id),
                ADD CONSTRAINT roadmap_snapshots_completed_iff CHECK ((status = 'COMPLETED') = (completed_at IS NOT NULL)),
                ADD CONSTRAINT roadmap_snapshots_versions_format CHECK (
                    roadmap_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$' AND rules_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'
                    AND skill_gap_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$' AND challenge_catalog_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
                ADD CONSTRAINT roadmap_snapshots_fingerprints_sha256 CHECK (
                    catalog_fingerprint ~ '^[0-9a-f]{64}$' AND rules_fingerprint ~ '^[0-9a-f]{64}$' AND roadmap_fingerprint ~ '^[0-9a-f]{64}$'
                    AND skill_gap_specification_fingerprint ~ '^[0-9a-f]{64}$' AND challenge_catalog_fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT roadmap_snapshots_focus_object CHECK (jsonb_typeof(focus) = 'object' AND octet_length(focus::text) <= 32768),
                ADD CONSTRAINT roadmap_snapshots_tracks_array CHECK (
                    jsonb_typeof(tracks) = 'array' AND jsonb_array_length(tracks) BETWEEN 1 AND 10 AND octet_length(tracks::text) <= 32768),
                ADD CONSTRAINT roadmap_snapshots_size CHECK (step_count BETWEEN 1 AND 100 AND estimated_minutes BETWEEN 1 AND 100000)
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX roadmap_snapshots_one_active_unique ON roadmap_snapshots (project_id) WHERE status = 'ACTIVE'
        SQL);

        Schema::create('roadmap_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('roadmap_snapshot_id');
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('track_position');
            $table->unsignedSmallInteger('step_position');
            $table->string('track_key', 64);
            $table->string('competency_key', 64);
            $table->string('step_key', 48);
            $table->string('type', 16);
            $table->string('title', 120);
            $table->text('description');
            $table->string('objective', 300);
            $table->unsignedSmallInteger('estimated_minutes');
            $table->jsonb('prerequisites');
            $table->string('challenge_key', 64)->nullable();
            $table->string('challenge_version', 32)->nullable();
            $table->string('challenge_title', 120)->nullable();
            $table->string('challenge_difficulty', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['roadmap_snapshot_id', 'project_id', 'user_id'], 'roadmap_steps_snapshot_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('roadmap_snapshots')
                ->restrictOnDelete();
            $table->unique(['roadmap_snapshot_id', 'step_key'], 'roadmap_steps_key_unique');
            $table->unique(['roadmap_snapshot_id', 'position'], 'roadmap_steps_position_unique');
            $table->unique(['roadmap_snapshot_id', 'track_position', 'step_position'], 'roadmap_steps_track_position_unique');
            // Target of the completions' composite foreign key.
            $table->unique(['id', 'roadmap_snapshot_id'], 'roadmap_steps_snapshot_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE roadmap_steps
                ADD CONSTRAINT roadmap_steps_type_valid CHECK (type IN ('READ', 'PRACTICE', 'CHALLENGE', 'REASSESS')),
                ADD CONSTRAINT roadmap_steps_positions CHECK (
                    position BETWEEN 1 AND 100 AND track_position BETWEEN 1 AND 10 AND step_position BETWEEN 1 AND 20),
                ADD CONSTRAINT roadmap_steps_key_format CHECK (step_key ~ '^(cm|fd|ts|ch)-[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT roadmap_steps_estimate CHECK (estimated_minutes BETWEEN 5 AND 480),
                ADD CONSTRAINT roadmap_steps_description_bounded CHECK (char_length(description) BETWEEN 1 AND 600),
                ADD CONSTRAINT roadmap_steps_prerequisites_array CHECK (jsonb_typeof(prerequisites) = 'array' AND octet_length(prerequisites::text) <= 2048),
                ADD CONSTRAINT roadmap_steps_challenge_reference CHECK (
                    (challenge_key IS NULL) = (challenge_version IS NULL)
                    AND (challenge_key IS NULL) = (challenge_title IS NULL)
                    AND (challenge_key IS NULL) = (challenge_difficulty IS NULL)
                    AND (challenge_key IS NULL OR type = 'CHALLENGE'))
        SQL);

        Schema::create('roadmap_step_completions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('roadmap_snapshot_id');
            $table->ulid('roadmap_step_id');
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->timestamp('completed_at')->useCurrent();

            $table->foreign(['roadmap_step_id', 'roadmap_snapshot_id'], 'roadmap_step_completions_step_foreign')
                ->references(['id', 'roadmap_snapshot_id'])->on('roadmap_steps')
                ->restrictOnDelete();
            $table->foreign(['roadmap_snapshot_id', 'project_id', 'user_id'], 'roadmap_step_completions_snapshot_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('roadmap_snapshots')
                ->restrictOnDelete();
            $table->unique('roadmap_step_id', 'roadmap_step_completions_step_unique');
            $table->index('roadmap_snapshot_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION roadmap_snapshots_guard_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('COMPLETED', 'SUPERSEDED') THEN
                    RAISE EXCEPTION 'roadmap_snapshots: a % roadmap is final', OLD.status USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.user_id, NEW.project_id, NEW.skill_gap_snapshot_id, NEW.competency_snapshot_id, NEW.dna_snapshot_id,
                    NEW.analysis_run_id, NEW.source_snapshot_id, NEW.roadmap_version, NEW.rules_version, NEW.catalog_fingerprint,
                    NEW.rules_fingerprint, NEW.roadmap_fingerprint, NEW.skill_gap_version, NEW.skill_gap_specification_fingerprint,
                    NEW.target_profile, NEW.target_profile_version, NEW.challenge_catalog_version, NEW.challenge_catalog_fingerprint,
                    NEW.focus, NEW.tracks, NEW.step_count, NEW.estimated_minutes, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.user_id, OLD.project_id, OLD.skill_gap_snapshot_id, OLD.competency_snapshot_id, OLD.dna_snapshot_id,
                    OLD.analysis_run_id, OLD.source_snapshot_id, OLD.roadmap_version, OLD.rules_version, OLD.catalog_fingerprint,
                    OLD.rules_fingerprint, OLD.roadmap_fingerprint, OLD.skill_gap_version, OLD.skill_gap_specification_fingerprint,
                    OLD.target_profile, OLD.target_profile_version, OLD.challenge_catalog_version, OLD.challenge_catalog_fingerprint,
                    OLD.focus, OLD.tracks, OLD.step_count, OLD.estimated_minutes, OLD.created_at) THEN
                    RAISE EXCEPTION 'roadmap_snapshots: a roadmap''s content and provenance are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER roadmap_snapshots_guarded
                BEFORE UPDATE ON roadmap_snapshots
                FOR EACH ROW EXECUTE FUNCTION roadmap_snapshots_guard_update();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_step_completions');
        Schema::dropIfExists('roadmap_steps');
        DB::unprepared('DROP TRIGGER IF EXISTS roadmap_snapshots_guarded ON roadmap_snapshots');
        Schema::dropIfExists('roadmap_snapshots');
        DB::unprepared('DROP FUNCTION IF EXISTS roadmap_snapshots_guard_update()');
    }
};
