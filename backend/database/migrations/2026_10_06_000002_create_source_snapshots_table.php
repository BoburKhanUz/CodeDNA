<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable reference to the exact source analyzed
     * (docs/architecture/data-model.md#source-snapshots). The source itself
     * lives in S3-compatible storage (ADR-003); only its location, hash and
     * size are recorded here. No updated_at: rows are never updated.
     */
    public function up(): void
    {
        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            // 1, 2, 3, … per project (assigned by RecordSourceSnapshot).
            $table->unsignedInteger('version');
            $table->string('source_type', 32);
            $table->string('storage_disk', 64);
            $table->string('storage_key', 1024);
            // SHA-256 of the archive (content identity), lowercase hex.
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('file_count');
            $table->string('primary_language', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Snapshot history of a project, newest first (also covers project_id lookups).
            $table->unique(['project_id', 'version']);
            // "Has this project already uploaded identical content?" (not unique: re-uploads are allowed).
            $table->index(['project_id', 'source_hash']);
            // One stored object belongs to exactly one snapshot.
            $table->unique(['storage_disk', 'storage_key']);
            // Target of analysis_runs' composite foreign key (source_snapshot_id, project_id).
            $table->unique(['id', 'project_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE source_snapshots
                ADD CONSTRAINT source_snapshots_version_positive CHECK (version >= 1),
                ADD CONSTRAINT source_snapshots_source_type_valid CHECK (source_type IN ('UPLOAD', 'REPOSITORY')),
                ADD CONSTRAINT source_snapshots_storage_disk_not_blank CHECK (btrim(storage_disk) <> ''),
                ADD CONSTRAINT source_snapshots_storage_key_safe
                    CHECK (btrim(storage_key) <> '' AND storage_key !~ '(^/|\.\.)'),
                ADD CONSTRAINT source_snapshots_source_hash_sha256 CHECK (source_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT source_snapshots_size_non_negative CHECK (size_bytes >= 0),
                ADD CONSTRAINT source_snapshots_file_count_non_negative CHECK (file_count >= 0),
                ADD CONSTRAINT source_snapshots_primary_language_format
                    CHECK (primary_language IS NULL OR primary_language ~ '^[a-z][a-z0-9+#._-]*$'),
                ADD CONSTRAINT source_snapshots_metadata_object
                    CHECK (metadata IS NULL OR (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 16384))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('source_snapshots');
    }
};
