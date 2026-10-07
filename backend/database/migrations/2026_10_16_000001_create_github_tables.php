<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub integration (Phase 19, docs/architecture/github-integration-v1.md).
     * GitHub is a source provider: these tables hold the user's GitHub
     * authorization, the projects' repository connections and the imports.
     * Imported source lives only in source_snapshots and object storage.
     *
     * - github_accounts: one GitHub identity per CodeDNA user; its user
     *   access and refresh tokens are encrypted by the application
     *   (Laravel Crypt, APP_KEY) and never leave the server.
     * - github_oauth_states: single-use, short-lived authorization states,
     *   stored as SHA-256 hashes and bound to one user.
     * - github_connections: a project's connected repository and branch;
     *   at most one ACTIVE per project. Identity columns never change, and a
     *   disconnected connection is kept as provenance of its imports.
     * - github_imports: one row per import request. A terminal import never
     *   changes, and one snapshot exists per (project, repository, commit).
     *
     * No foreign key cascades: disconnecting or unlinking never removes a
     * source snapshot or anything analyzed from it.
     */
    public function up(): void
    {
        Schema::create('github_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('github_user_id');
            $table->string('login', 39);
            $table->text('access_token');
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamps();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE github_accounts
                ADD CONSTRAINT github_accounts_github_user_id_positive CHECK (github_user_id > 0),
                ADD CONSTRAINT github_accounts_login_format CHECK (login ~ '^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$'),
                ADD CONSTRAINT github_accounts_tokens_not_blank CHECK (btrim(access_token) <> '' AND (refresh_token IS NULL OR btrim(refresh_token) <> '')),
                ADD CONSTRAINT github_accounts_refresh_expiry CHECK (refresh_token IS NOT NULL OR refresh_token_expires_at IS NULL)
        SQL);

        Schema::create('github_oauth_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            // Optional project to return to, owned by the same user (composite FK below).
            $table->ulid('project_id')->nullable();
            $table->char('state_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['project_id', 'user_id'], 'github_oauth_states_project_foreign')
                ->references(['id', 'user_id'])->on('projects')->restrictOnDelete();
            $table->index(['user_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE github_oauth_states
                ADD CONSTRAINT github_oauth_states_hash_sha256 CHECK (state_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT github_oauth_states_expiry CHECK (expires_at > created_at AND expires_at <= created_at + interval '1 hour'),
                ADD CONSTRAINT github_oauth_states_consumed_in_time CHECK (consumed_at IS NULL OR consumed_at >= created_at)
        SQL);

        Schema::create('github_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('user_id');
            // GitHub identities, verified with GitHub when connecting.
            $table->unsignedBigInteger('installation_id');
            $table->unsignedBigInteger('repository_id');
            $table->string('repository_owner', 39);
            $table->string('repository_name', 100);
            $table->string('repository_full_name', 140);
            $table->boolean('repository_private');
            $table->boolean('repository_archived');
            $table->string('default_branch', 255);
            $table->string('branch', 255);
            $table->string('status', 16);
            $table->char('last_imported_commit_sha', 40)->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamp('metadata_verified_at');
            $table->timestamp('connected_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->foreign(['project_id', 'user_id'], 'github_connections_project_foreign')
                ->references(['id', 'user_id'])->on('projects')->restrictOnDelete();
            // Target of the imports' composite foreign key.
            $table->unique(['id', 'project_id', 'user_id'], 'github_connections_owner_unique');
            $table->index(['project_id', 'created_at']);
            $table->index('repository_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE github_connections
                ADD CONSTRAINT github_connections_status_valid CHECK (status IN ('ACTIVE', 'DISCONNECTED')),
                ADD CONSTRAINT github_connections_disconnected_iff CHECK ((status = 'DISCONNECTED') = (disconnected_at IS NOT NULL)),
                ADD CONSTRAINT github_connections_ids_positive CHECK (installation_id > 0 AND repository_id > 0),
                ADD CONSTRAINT github_connections_full_name CHECK (repository_full_name = repository_owner || '/' || repository_name),
                ADD CONSTRAINT github_connections_owner_format CHECK (repository_owner ~ '^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$'),
                ADD CONSTRAINT github_connections_name_format CHECK (repository_name ~ '^[A-Za-z0-9._-]{1,100}$' AND repository_name NOT IN ('.', '..')),
                ADD CONSTRAINT github_connections_branch_format CHECK (
                    branch ~ '^[A-Za-z0-9._/-]{1,255}$' AND branch !~ '(^[-/.]|/$|//|\.\.|\.lock$|/\.|@\{)'
                    AND default_branch ~ '^[A-Za-z0-9._/-]{1,255}$'),
                ADD CONSTRAINT github_connections_commit_sha CHECK (last_imported_commit_sha IS NULL OR last_imported_commit_sha ~ '^[0-9a-f]{40}$'),
                ADD CONSTRAINT github_connections_imported_together CHECK ((last_imported_commit_sha IS NULL) = (last_imported_at IS NULL))
        SQL);
        DB::statement("CREATE UNIQUE INDEX github_connections_one_active_unique ON github_connections (project_id) WHERE status = 'ACTIVE'");

        Schema::create('github_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('github_connection_id');
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->unsignedBigInteger('repository_id');
            $table->string('repository_full_name', 140);
            $table->string('ref', 255);
            $table->char('commit_sha', 40)->nullable();
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->ulid('source_snapshot_id')->nullable();
            // True for the import that created its snapshot; a repeat of the same commit reuses it.
            $table->boolean('created_snapshot')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(['github_connection_id', 'project_id', 'user_id'], 'github_imports_connection_foreign')
                ->references(['id', 'project_id', 'user_id'])->on('github_connections')->restrictOnDelete();
            $table->foreign(['source_snapshot_id', 'project_id'], 'github_imports_snapshot_foreign')
                ->references(['id', 'project_id'])->on('source_snapshots')->restrictOnDelete();
            $table->index(['project_id', 'created_at']);
            $table->index('source_snapshot_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE github_imports
                ADD CONSTRAINT github_imports_status_valid CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELLED')),
                ADD CONSTRAINT github_imports_commit_sha CHECK (commit_sha IS NULL OR commit_sha ~ '^[0-9a-f]{40}$'),
                ADD CONSTRAINT github_imports_succeeded_iff CHECK (
                    (status = 'SUCCEEDED') = (source_snapshot_id IS NOT NULL)
                    AND (status <> 'SUCCEEDED' OR commit_sha IS NOT NULL)),
                ADD CONSTRAINT github_imports_failed_iff CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
                ADD CONSTRAINT github_imports_failure_code_format CHECK (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]*$'),
                ADD CONSTRAINT github_imports_created_snapshot CHECK (NOT created_snapshot OR status = 'SUCCEEDED'),
                ADD CONSTRAINT github_imports_completed_iff_terminal CHECK (
                    (status IN ('SUCCEEDED', 'FAILED', 'CANCELLED')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT github_imports_started CHECK (status NOT IN ('RUNNING', 'SUCCEEDED') OR started_at IS NOT NULL),
                ADD CONSTRAINT github_imports_ref_format CHECK (ref ~ '^[A-Za-z0-9._/-]{1,255}$')
        SQL);
        // At most one import in progress per project.
        DB::statement("CREATE UNIQUE INDEX github_imports_one_active_unique ON github_imports (project_id) WHERE status IN ('QUEUED', 'RUNNING')");
        // Idempotency: one source snapshot per project, repository and commit.
        DB::statement('CREATE UNIQUE INDEX github_imports_commit_unique ON github_imports (project_id, repository_id, commit_sha) WHERE created_snapshot');

        DB::unprepared(<<<'SQL'
            -- Connections: identity never changes; status moves once, ACTIVE -> DISCONNECTED; a disconnected connection is frozen.
            CREATE OR REPLACE FUNCTION github_connections_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'DISCONNECTED' THEN
                    RAISE EXCEPTION 'github_connections: a disconnected connection never changes' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.project_id, NEW.user_id, NEW.installation_id, NEW.repository_id, NEW.connected_at, NEW.created_at)
                    IS DISTINCT FROM (OLD.project_id, OLD.user_id, OLD.installation_id, OLD.repository_id, OLD.connected_at, OLD.created_at) THEN
                    RAISE EXCEPTION 'github_connections: identity is immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER github_connections_guarded BEFORE UPDATE ON github_connections
                FOR EACH ROW EXECUTE FUNCTION github_connections_guard();

            -- Imports: identity never changes, status only moves forward, terminal imports are frozen.
            CREATE OR REPLACE FUNCTION github_imports_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('SUCCEEDED', 'FAILED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'github_imports: a finished import is immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.github_connection_id, NEW.project_id, NEW.user_id, NEW.repository_id, NEW.ref, NEW.created_at)
                    IS DISTINCT FROM (OLD.github_connection_id, OLD.project_id, OLD.user_id, OLD.repository_id, OLD.ref, OLD.created_at) THEN
                    RAISE EXCEPTION 'github_imports: identity is immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'RUNNING' AND NEW.status = 'QUEUED' THEN
                    RAISE EXCEPTION 'github_imports: status only moves forward' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER github_imports_guarded BEFORE UPDATE ON github_imports
                FOR EACH ROW EXECUTE FUNCTION github_imports_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('github_imports');
        Schema::dropIfExists('github_connections');
        Schema::dropIfExists('github_oauth_states');
        Schema::dropIfExists('github_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS github_imports_guard(); DROP FUNCTION IF EXISTS github_connections_guard();');
    }
};
