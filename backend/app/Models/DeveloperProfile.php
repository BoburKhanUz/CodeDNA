<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\SupportedLocale;
use Database\Factories\DeveloperProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A developer's product profile (docs/architecture/data-model.md#developer_profiles).
 *
 * Authentication identity (name, email, password) lives on User. Mass
 * assignment covers only the fields a developer edits; the owner is set
 * through the relationship (`$user->developerProfile()->create()`), and
 * metadata is internal and written explicitly by application code.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $display_name
 * @property string|null $bio
 * @property string|null $avatar_url
 * @property string $timezone IANA time zone identifier (presentation only; timestamps stay UTC)
 * @property SupportedLocale $locale
 * @property string|null $country_code ISO 3166-1 alpha-2 style, uppercase
 * @property string|null $city
 * @property string|null $job_title
 * @property string|null $company
 * @property string|null $website_url
 * @property string|null $github_username
 * @property string|null $linkedin_url
 * @property string|null $preferred_language
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'display_name', 'bio', 'avatar_url', 'timezone', 'locale', 'country_code', 'city',
    'job_title', 'company', 'website_url', 'github_username', 'linkedin_url', 'preferred_language',
])]
class DeveloperProfile extends Model
{
    /** @use HasFactory<DeveloperProfileFactory> */
    use HasFactory, HasUlids;

    public const DEFAULT_TIMEZONE = 'UTC';

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
        'locale' => 'en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locale' => SupportedLocale::class,
            'metadata' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
