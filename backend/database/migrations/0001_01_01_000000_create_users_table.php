<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal authentication user (Phase 03). Developer profile data, skills
     * and integrations belong to later phases and live in their own tables.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // ULIDs: sortable and not enumerable (docs/api/README.md).
            $table->ulid('id')->primary();
            $table->string('name');
            // Stored lowercase (normalized on input), so uniqueness is case-insensitive.
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
