<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A developer's project (docs/architecture/data-model.md#projects).
     *
     * State values are VARCHAR + CHECK constraints (not PostgreSQL ENUM types)
     * and are written out here on purpose: a migration must keep meaning what
     * it meant when it was written, even if the PHP enums evolve.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // RESTRICT: removing a user never silently deletes their projects and history.
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('default_branch')->nullable();
            $table->string('source_type', 32);
            $table->string('repository_url', 2048)->nullable();
            $table->string('language', 64)->nullable();
            $table->string('status', 32)->default('ACTIVE');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // Slugs are unique per owner; also serves "projects of a user" lookups.
            $table->unique(['user_id', 'slug']);
            // A user's project list filtered by status (active vs archived).
            $table->index(['user_id', 'status']);
            // Target of dna_snapshots' composite foreign key (project_id, user_id).
            $table->unique(['id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE projects
                ADD CONSTRAINT projects_name_not_blank CHECK (btrim(name) <> ''),
                ADD CONSTRAINT projects_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT projects_source_type_valid CHECK (source_type IN ('UPLOAD', 'REPOSITORY')),
                ADD CONSTRAINT projects_status_valid CHECK (status IN ('ACTIVE', 'ARCHIVED')),
                ADD CONSTRAINT projects_repository_url_matches_source_type
                    CHECK ((source_type = 'REPOSITORY') = (repository_url IS NOT NULL)),
                ADD CONSTRAINT projects_repository_url_https
                    CHECK (repository_url IS NULL OR repository_url ~ '^https://[^[:space:]]+$'),
                ADD CONSTRAINT projects_language_format
                    CHECK (language IS NULL OR language ~ '^[a-z][a-z0-9+#._-]*$'),
                ADD CONSTRAINT projects_metadata_object
                    CHECK (metadata IS NULL OR (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 16384))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
