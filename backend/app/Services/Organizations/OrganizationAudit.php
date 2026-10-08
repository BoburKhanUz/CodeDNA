<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * Writes the organization audit log (docs/teams/teams-architecture.md#audit-log).
 * Called inside the transaction of the change it records, so an entry exists
 * exactly when the change does. Actions are server-owned; metadata is small,
 * structured and never holds tokens, secrets or source code (keys that look
 * like one are refused).
 */
final class OrganizationAudit
{
    private const FORBIDDEN_KEYS = '/token|secret|password|session|cookie|authorization|api_key|source/i';

    /**
     * @param  array<string, scalar|null|array<string, scalar|null>>  $metadata
     */
    public function record(Organization|string $organization, User|string|null $actor, OrganizationAuditAction $action, ?Model $target = null, array $metadata = []): OrganizationAuditEvent
    {
        array_walk_recursive($metadata, static function (mixed $value, string|int $key): void {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEYS, $key) === 1) {
                throw new LogicException("Audit metadata must not contain \"{$key}\".");
            }
        });
        foreach (array_keys($metadata) as $key) {
            if (preg_match(self::FORBIDDEN_KEYS, (string) $key) === 1) {
                throw new LogicException("Audit metadata must not contain \"{$key}\".");
            }
        }
        $requestId = Context::get('request_id');

        $event = new OrganizationAuditEvent;
        $event->forceFill([
            'organization_id' => $organization instanceof Organization ? $organization->getKey() : $organization,
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : $actor,
            'action' => $action,
            'target_type' => $target === null ? null : self::targetType($target),
            'target_id' => $target?->getKey(),
            'metadata' => $metadata,
            'request_id' => is_string($requestId) ? $requestId : null,
        ])->save();

        return $event;
    }

    private static function targetType(Model $target): string
    {
        return match (class_basename($target)) {
            'Organization' => 'organization',
            'OrganizationMembership' => 'membership',
            'OrganizationInvitation' => 'invitation',
            'Project' => 'project',
            default => throw new LogicException('Unsupported audit target '.$target::class),
        };
    }
}
