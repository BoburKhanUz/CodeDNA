<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotent source uploads (docs/api/README.md#idempotency).
     *
     * Stores the SHA-256 of the client's Idempotency-Key header, never the
     * raw value. Unique per project: a retried upload with the same key finds
     * the snapshot the first attempt created instead of creating another.
     * NULL when the client sent no key. Written once at insert, like every
     * other snapshot column.
     */
    public function up(): void
    {
        Schema::table('source_snapshots', function (Blueprint $table) {
            $table->char('idempotency_key_hash', 64)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE source_snapshots
                ADD CONSTRAINT source_snapshots_idempotency_key_hash_sha256
                    CHECK (idempotency_key_hash IS NULL OR idempotency_key_hash ~ '^[0-9a-f]{64}$')
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX source_snapshots_project_idempotency_unique
                ON source_snapshots (project_id, idempotency_key_hash)
                WHERE idempotency_key_hash IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // PostgreSQL drops the column's CHECK constraint and partial index with it.
        Schema::table('source_snapshots', function (Blueprint $table) {
            $table->dropColumn('idempotency_key_hash');
        });
    }
};
