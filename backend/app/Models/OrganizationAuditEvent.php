<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Enums\Organizations\OrganizationAuditAction;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of an organization's audit log (Phase 24). Append-only; written
 * only by OrganizationAudit, with server-owned actions and metadata that
 * never holds tokens, secrets or source code.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $actor_user_id
 * @property OrganizationAuditAction $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed> $metadata
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class OrganizationAuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'updated'));
        static::deleting(static fn (self $event) => throw DomainRuleViolation::immutable($event, 'deleted'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => OrganizationAuditAction::class,
            'metadata' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
