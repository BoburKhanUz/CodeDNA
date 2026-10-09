<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitLab and Bitbucket Cloud (Phase 28, docs/integrations/provider-architecture.md).
     * Additive: new tables only; the GitHub tables are untouched. One set of
     * tables for every OAuth repository provider, keyed by `provider`.
     *
     * - repository_provider_accounts: a user's authorization with a provider.
     *   Tokens are encrypted by the application (Laravel Crypt, APP_KEY) and
     *   never leave the server. One account per (user, provider), and one
     *   CodeDNA user per provider identity: an identity already linked to
     *   another user is never taken over.
     * - repository_provider_oauth_states: single-use, short-lived states,
     *   stored as SHA-256 hashes and bound to one user and one provider.
     * - repository_provider_connections: a project's connected repository and
     *   branch; at most one ACTIVE per project (and, enforced under the
     *   project lock, none while a GitHub connection is ACTIVE). Identity
     *   columns never change; a disconnected connection is kept as provenance.
     * - repository_provider_imports: one row per import request; a terminal
     *   import never changes; one snapshot per (project, provider, repository, commit).
     *
     * No foreign key cascades: disconnecting or unlinking never removes a
     * source snapshot or anything analyzed from it.
     */
    public function up(): void
    {
        Schema::create('repository_provider_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('provider_user_id', 64);
            $table->string('username', 255);
            $table->text('access_token');
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->unique(['provider', 'provider_user_id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE repository_provider_accounts
                ADD CONSTRAINT repository_provider_accounts_provider_valid CHECK (provider IN ('gitlab', 'bitbucket')),
                ADD CONSTRAINT repository_provider_accounts_identity_not_blank CHECK (btrim(provider_user_id) <> '' AND btrim(username) <> ''),
                ADD CONSTRAINT repository_provider_accounts_tokens_not_blank CHECK (btrim(access_token) <> '' AND (refresh_token IS NULL OR btrim(refresh_token) <> ''))
        SQL);

        Schema::create('repository_provider_oauth_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 16);
            // Optional project to return to; access is checked again when the flow completes.
            $table->foreignUlid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('state_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE repository_provider_oauth_states
                ADD CONSTRAINT repository_provider_oauth_states_provider_valid CHECK (provider IN ('gitlab', 'bitbucket')),
                ADD CONSTRAINT repository_provider_oauth_states_hash_sha256 CHECK (state_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT repository_provider_oauth_states_expiry CHECK (expires_at > created_at AND expires_at <= created_at + interval '1 hour'),
                ADD CONSTRAINT repository_provider_oauth_states_consumed_in_time CHECK (consumed_at IS NULL OR consumed_at >= created_at)
        SQL);

        Schema::create('repository_provider_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->string('provider', 16);
            // Provider identity, verified with the provider as the connecting user.
            $table->string('repository_id', 80);
            $table->string('repository_full_name', 255);
            $table->boolean('repository_private');
            $table->boolean('repository_archived');
            $table->string('default_branch', 255)->nullable();
            $table->string('branch', 255);
            $table->string('status', 16);
            // Who connected it (an owner or, on a team project, an admin).
            $table->foreignUlid('connected_by')->constrained('users')->restrictOnDelete();
            $table->char('last_imported_commit_sha', 40)->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamp('metadata_verified_at');
            $table->timestamp('connected_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            // Target of the imports' composite foreign key.
            $table->unique(['id', 'project_id'], 'repository_provider_connections_project_unique');
            $table->index(['project_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE repository_provider_connections
                ADD CONSTRAINT repository_provider_connections_provider_valid CHECK (provider IN ('gitlab', 'bitbucket')),
                ADD CONSTRAINT repository_provider_connections_status_valid CHECK (status IN ('ACTIVE', 'DISCONNECTED')),
                ADD CONSTRAINT repository_provider_connections_disconnected_iff CHECK ((status = 'DISCONNECTED') = (disconnected_at IS NOT NULL)),
                ADD CONSTRAINT repository_provider_connections_repository_id CHECK (repository_id ~ '^[0-9A-Za-z{}/-]{1,80}$'),
                ADD CONSTRAINT repository_provider_connections_branch_format CHECK (
                    branch ~ '^[A-Za-z0-9._/-]{1,255}$' AND branch !~ '(^[-/.]|/$|//|\.\.|\.lock$|/\.|@\{)'),
                ADD CONSTRAINT repository_provider_connections_commit_sha CHECK (last_imported_commit_sha IS NULL OR last_imported_commit_sha ~ '^[0-9a-f]{40}$'),
                ADD CONSTRAINT repository_provider_connections_imported_together CHECK ((last_imported_commit_sha IS NULL) = (last_imported_at IS NULL))
        SQL);
        DB::statement("CREATE UNIQUE INDEX repository_provider_connections_one_active_unique ON repository_provider_connections (project_id) WHERE status = 'ACTIVE'");

        Schema::create('repository_provider_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('connection_id');
            $table->ulid('project_id');
            $table->string('provider', 16);
            // Whose provider authorization the import uses (re-verified when requested).
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('repository_id', 80);
            $table->string('repository_full_name', 255);
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

            $table->foreign(['connection_id', 'project_id'], 'repository_provider_imports_connection_foreign')
                ->references(['id', 'project_id'])->on('repository_provider_connections')->restrictOnDelete();
            $table->foreign(['source_snapshot_id', 'project_id'], 'repository_provider_imports_snapshot_foreign')
                ->references(['id', 'project_id'])->on('source_snapshots')->restrictOnDelete();
            $table->index(['project_id', 'created_at']);
            $table->index('source_snapshot_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE repository_provider_imports
                ADD CONSTRAINT repository_provider_imports_provider_valid CHECK (provider IN ('gitlab', 'bitbucket')),
                ADD CONSTRAINT repository_provider_imports_status_valid CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELLED')),
                ADD CONSTRAINT repository_provider_imports_commit_sha CHECK (commit_sha IS NULL OR commit_sha ~ '^[0-9a-f]{40}$'),
                ADD CONSTRAINT repository_provider_imports_succeeded_iff CHECK (
                    (status = 'SUCCEEDED') = (source_snapshot_id IS NOT NULL)
                    AND (status <> 'SUCCEEDED' OR commit_sha IS NOT NULL)),
                ADD CONSTRAINT repository_provider_imports_failed_iff CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
                ADD CONSTRAINT repository_provider_imports_failure_code_format CHECK (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]*$'),
                ADD CONSTRAINT repository_provider_imports_created_snapshot CHECK (NOT created_snapshot OR status = 'SUCCEEDED'),
                ADD CONSTRAINT repository_provider_imports_completed_iff_terminal CHECK (
                    (status IN ('SUCCEEDED', 'FAILED', 'CANCELLED')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT repository_provider_imports_started CHECK (status NOT IN ('RUNNING', 'SUCCEEDED') OR started_at IS NOT NULL),
                ADD CONSTRAINT repository_provider_imports_ref_format CHECK (ref ~ '^[A-Za-z0-9._/-]{1,255}$')
        SQL);
        // At most one import in progress per project.
        DB::statement("CREATE UNIQUE INDEX repository_provider_imports_one_active_unique ON repository_provider_imports (project_id) WHERE status IN ('QUEUED', 'RUNNING')");
        // Idempotency: one source snapshot per project, provider, repository and commit.
        DB::statement('CREATE UNIQUE INDEX repository_provider_imports_commit_unique ON repository_provider_imports (project_id, provider, repository_id, commit_sha) WHERE created_snapshot');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION repository_provider_connections_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'DISCONNECTED' THEN
                    RAISE EXCEPTION 'repository_provider_connections: a disconnected connection never changes' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.project_id, NEW.provider, NEW.repository_id, NEW.connected_by, NEW.connected_at, NEW.created_at)
                    IS DISTINCT FROM (OLD.project_id, OLD.provider, OLD.repository_id, OLD.connected_by, OLD.connected_at, OLD.created_at) THEN
                    RAISE EXCEPTION 'repository_provider_connections: identity is immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER repository_provider_connections_guarded BEFORE UPDATE ON repository_provider_connections
                FOR EACH ROW EXECUTE FUNCTION repository_provider_connections_guard();

            CREATE OR REPLACE FUNCTION repository_provider_imports_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('SUCCEEDED', 'FAILED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'repository_provider_imports: a finished import is immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.connection_id, NEW.project_id, NEW.provider, NEW.requested_by, NEW.repository_id, NEW.ref, NEW.created_at)
                    IS DISTINCT FROM (OLD.connection_id, OLD.project_id, OLD.provider, OLD.requested_by, OLD.repository_id, OLD.ref, OLD.created_at) THEN
                    RAISE EXCEPTION 'repository_provider_imports: identity is immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'RUNNING' AND NEW.status = 'QUEUED' THEN
                    RAISE EXCEPTION 'repository_provider_imports: status only moves forward' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER repository_provider_imports_guarded BEFORE UPDATE ON repository_provider_imports
                FOR EACH ROW EXECUTE FUNCTION repository_provider_imports_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS repository_provider_imports_guarded ON repository_provider_imports;
            DROP FUNCTION IF EXISTS repository_provider_imports_guard();
            DROP TRIGGER IF EXISTS repository_provider_connections_guarded ON repository_provider_connections;
            DROP FUNCTION IF EXISTS repository_provider_connections_guard();
        SQL);
        Schema::dropIfExists('repository_provider_imports');
        Schema::dropIfExists('repository_provider_connections');
        Schema::dropIfExists('repository_provider_oauth_states');
        Schema::dropIfExists('repository_provider_accounts');
    }
};
