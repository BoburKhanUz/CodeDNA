<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Exceptions\DomainRuleViolation;
use App\Services\Challenge\ChallengeDefinitionData;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A published catalog definition as it was used (Phase 16), stored once per
 * (key, version) with its fingerprints and full document, hidden cases
 * included. Never updated (also a database trigger) or deleted; the
 * document never leaves the server except through
 * ChallengeDefinitionData::publicView().
 *
 * @property string $id
 * @property string $key
 * @property string $version
 * @property string $catalog_version
 * @property string $category
 * @property string $difficulty
 * @property string $language
 * @property string $runtime
 * @property string $title
 * @property string $definition_fingerprint
 * @property string $test_suite_fingerprint
 * @property array<string, mixed> $document
 * @property Carbon|null $created_at
 */
class ChallengeDefinition extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $definition) => throw DomainRuleViolation::immutable($definition, 'updated'));
        static::deleting(static fn (self $definition) => throw DomainRuleViolation::immutable($definition, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['document' => JsonObject::class];
    }

    public function data(): ChallengeDefinitionData
    {
        return new ChallengeDefinitionData($this->document);
    }
}
