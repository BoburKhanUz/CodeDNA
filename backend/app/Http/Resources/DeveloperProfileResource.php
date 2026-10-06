<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeveloperProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a developer profile. Fields are listed explicitly:
 * the owner ID, internal metadata and any future attribute are never exposed
 * unless they are added here.
 *
 * @mixin DeveloperProfile
 */
final class DeveloperProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'developer_profile',
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'timezone' => $this->timezone,
            'locale' => $this->locale->value,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'job_title' => $this->job_title,
            'company' => $this->company,
            'website_url' => $this->website_url,
            'github_username' => $this->github_username,
            'linkedin_url' => $this->linkedin_url,
            'preferred_language' => $this->preferred_language,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Always 200: a default profile created on first access
     * (ResolveDeveloperProfile) is not a resource the client created.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }
}
