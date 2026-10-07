<?php

declare(strict_types=1);

namespace Tests\Unit\Growth;

use App\Models\GrowthObservation;
use App\Services\Growth\GrowthEvents;
use Tests\TestCase;

/**
 * Growth events are derived from stored observations only: meaningful
 * changes, gap transitions and level changes; nothing for unchanged or
 * unmeasured metrics.
 */
final class GrowthEventsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function observation(array $attributes): GrowthObservation
    {
        return (new GrowthObservation)->forceFill($attributes + [
            'metric_type' => 'COMPETENCY', 'metric_key' => 'FUNCTION_DESIGN', 'previous_state' => 'ASSESSED', 'current_state' => 'ASSESSED',
            'previous_value' => '0.5000', 'current_value' => '0.5000', 'delta' => '0.0000', 'previous_level' => null, 'current_level' => null,
            'level_change' => null, 'status' => 'UNCHANGED',
        ]);
    }

    public function test_events_are_derived_deterministically(): void
    {
        $events = GrowthEvents::from([
            self::observation([]),
            self::observation(['metric_key' => 'TYPE_STRUCTURE', 'previous_value' => null, 'current_value' => null, 'delta' => null, 'status' => 'INSUFFICIENT_EVIDENCE']),
            self::observation(['metric_key' => 'CODE_HYGIENE', 'current_value' => '0.6000', 'delta' => '0.1000', 'status' => 'IMPROVED',
                'previous_level' => 'DEVELOPING', 'current_level' => 'ESTABLISHED', 'level_change' => 'UP']),
            self::observation(['metric_type' => 'DNA', 'metric_key' => 'OVERALL', 'current_value' => '0.4000', 'delta' => '-0.1000', 'status' => 'REGRESSED']),
            self::observation(['metric_type' => 'SKILL_GAP', 'previous_state' => 'GAP', 'current_state' => 'NO_GAP', 'previous_value' => '0.0600', 'current_value' => '0.0400', 'delta' => '-0.0200', 'status' => 'IMPROVED']),
            self::observation(['metric_type' => 'SKILL_GAP', 'metric_key' => 'CODE_HYGIENE', 'previous_state' => 'NO_GAP', 'current_state' => 'GAP', 'previous_value' => '0.0400', 'current_value' => '0.0600', 'delta' => '0.0200', 'status' => 'REGRESSED']),
            self::observation(['metric_key' => 'COMPLEXITY_MANAGEMENT', 'previous_level' => 'STRONG', 'current_level' => 'ESTABLISHED', 'level_change' => 'DOWN']),
            self::observation(['metric_type' => 'SKILL_GAP', 'metric_key' => 'TYPE_STRUCTURE', 'previous_state' => 'INSUFFICIENT_EVIDENCE', 'current_state' => 'GAP', 'previous_value' => null, 'current_value' => null, 'delta' => null, 'status' => 'INSUFFICIENT_EVIDENCE']),
        ]);

        $this->assertSame([
            ['COMPETENCY', 'CODE_HYGIENE', 'IMPROVED'],
            ['COMPETENCY', 'CODE_HYGIENE', 'LEVEL_UP'],
            ['DNA', 'OVERALL', 'REGRESSED'],
            ['SKILL_GAP', 'FUNCTION_DESIGN', 'GAP_CLOSED'],
            ['SKILL_GAP', 'CODE_HYGIENE', 'GAP_OPENED'],
            ['COMPETENCY', 'COMPLEXITY_MANAGEMENT', 'LEVEL_DOWN'],
        ], array_map(fn (array $e): array => [$e['metric_type'], $e['metric_key'], $e['kind']], $events));
        $this->assertSame(['kind' => 'LEVEL_DOWN', 'previous_level' => 'STRONG', 'current_level' => 'ESTABLISHED', 'metric_type' => 'COMPETENCY',
            'metric_key' => 'COMPLEXITY_MANAGEMENT', 'previous_value' => '0.5000', 'current_value' => '0.5000', 'delta' => '0.0000'], $events[5]);
    }
}
