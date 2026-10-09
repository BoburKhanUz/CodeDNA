<?php

declare(strict_types=1);

namespace App\Http\Requests\Repositories;

use App\Http\Requests\GitHub\OnlyFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/repository-providers/{provider}/callback: the state CodeDNA
 * issued and the code the provider returned. Nothing else is accepted.
 */
final class CompleteProviderAuthorizationRequest extends FormRequest
{
    use OnlyFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_.~-]{1,512}$/'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['state', 'code'];
    }
}
