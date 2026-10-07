<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\GrowthObservation;

/**
 * Deterministic growth events of a compared assessment, derived from its
 * stored observations (docs/architecture/growth-tracking-v1.md#events):
 * meaningful changes, skill gaps opening or closing, and competency level
 * changes. No generated text, and no reference to learning activity.
 */
final class GrowthEvents
{
    /**
     * @param  iterable<GrowthObservation>  $observations
     * @return list<array<string, mixed>>
     */
    public static function from(iterable $observations): array
    {
        $events = [];
        foreach ($observations as $o) {
            $base = [
                'metric_type' => $o->metric_type->value,
                'metric_key' => $o->metric_key,
                'previous_value' => $o->previous_value,
                'current_value' => $o->current_value,
                'delta' => $o->delta,
            ];
            $gapTransition = $o->metric_type->value === 'SKILL_GAP' && $o->previous_state !== $o->current_state
                && in_array($o->status->value, ['IMPROVED', 'REGRESSED'], true);
            if ($gapTransition) {
                $events[] = ['kind' => $o->current_state === 'NO_GAP' ? 'GAP_CLOSED' : 'GAP_OPENED'] + $base;
            } elseif (in_array($o->status->value, ['IMPROVED', 'REGRESSED'], true)) {
                $events[] = ['kind' => $o->status->value] + $base;
            }
            if ($o->level_change === 'UP' || $o->level_change === 'DOWN') {
                $events[] = ['kind' => $o->level_change === 'UP' ? 'LEVEL_UP' : 'LEVEL_DOWN', 'previous_level' => $o->previous_level, 'current_level' => $o->current_level] + $base;
            }
        }

        return $events;
    }
}
