<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A developer's product profile, one per user
     * (docs/architecture/data-model.md#developer_profiles).
     *
     * Authentication identity (name, email, password) stays in `users`.
     * Allowed values (IANA time zones, supported locales, programming
     * languages) are validated by the application; the database checks
     * formats and lengths so that no write path can store malformed data.
     */
    public function up(): void
    {
        Schema::create('developer_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // UNIQUE: exactly one profile per user. RESTRICT: deleting a user
            // never silently deletes data; account removal is a future
            // explicit purge workflow.
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('display_name', 100)->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->char('country_code', 2)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('company', 100)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('github_username', 39)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->string('preferred_language', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE developer_profiles
                ADD CONSTRAINT developer_profiles_text_not_blank CHECK (
                    (display_name IS NULL OR btrim(display_name) <> '')
                    AND (city IS NULL OR btrim(city) <> '')
                    AND (job_title IS NULL OR btrim(job_title) <> '')
                    AND (company IS NULL OR btrim(company) <> '')
                ),
                ADD CONSTRAINT developer_profiles_bio_length
                    CHECK (bio IS NULL OR (btrim(bio) <> '' AND char_length(bio) <= 1000)),
                ADD CONSTRAINT developer_profiles_urls_https CHECK (
                    (avatar_url IS NULL OR avatar_url ~ '^https://[^[:space:]]+$')
                    AND (website_url IS NULL OR website_url ~ '^https://[^[:space:]]+$')
                    AND (linkedin_url IS NULL OR linkedin_url ~ '^https://[^[:space:]]+$')
                ),
                ADD CONSTRAINT developer_profiles_timezone_format
                    CHECK (timezone ~ '^[A-Za-z][A-Za-z0-9_+/-]*$'),
                ADD CONSTRAINT developer_profiles_locale_format
                    CHECK (locale ~ '^[a-z]{2}(-[A-Z]{2})?$'),
                ADD CONSTRAINT developer_profiles_country_code_format
                    CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT developer_profiles_github_username_format
                    CHECK (github_username IS NULL OR github_username ~ '^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$'),
                ADD CONSTRAINT developer_profiles_preferred_language_format
                    CHECK (preferred_language IS NULL OR preferred_language ~ '^[a-z][a-z0-9+#._-]*$'),
                ADD CONSTRAINT developer_profiles_metadata_object
                    CHECK (metadata IS NULL OR (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 16384))
        SQL);

        // Accounts created before this migration get the default profile, so
        // "every user has exactly one profile" holds from now on.
        DB::table('users')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('developer_profiles')
                ->whereColumn('developer_profiles.user_id', 'users.id'))
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $now = now();
                DB::table('developer_profiles')->insert($users->map(fn (object $user): array => [
                    // Same ID format as HasUlids (lowercase ULID).
                    'id' => strtolower((string) Str::ulid()),
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_profiles');
    }
};
