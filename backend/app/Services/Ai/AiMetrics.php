<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Operational counters for the AI gateway (Phase 29), kept in the shared
 * cache (Redis outside tests): calls per outcome (SUCCEEDED or a failure
 * code such as PROVIDER_TIMEOUT or SLOTS_BUSY), total and last duration, and
 * generations in flight. Counters only: no prompt, evidence, answer, user or
 * project. Best effort: a cache failure never fails a generation.
 */
final readonly class AiMetrics
{
    private const PREFIX = 'codedna:ai:metrics:';

    public const OUTCOMES = [
        'SUCCEEDED', 'PROVIDER_TIMEOUT', 'PROVIDER_RATE_LIMITED', 'PROVIDER_UNAVAILABLE', 'PROVIDER_AUTH_FAILED',
        'PROVIDER_REJECTED', 'OUTPUT_TOO_LARGE', 'INVALID_OUTPUT', 'INPUT_TOO_LARGE', 'SLOTS_BUSY', 'ERROR',
    ];

    public function __construct(private Cache $cache) {}

    public function record(string $outcome, int $durationMs): void
    {
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'ERROR';
        $this->safely(function () use ($outcome, $durationMs): void {
            $this->cache->increment(self::PREFIX.'count:'.$outcome);
            if ($durationMs > 0) {
                $this->cache->increment(self::PREFIX.'duration_ms_total', $durationMs);
                $this->cache->forever(self::PREFIX.'last_duration_ms', $durationMs);
            }
        });
    }

    public function started(): void
    {
        $this->safely(fn () => $this->cache->increment(self::PREFIX.'in_flight'));
    }

    public function finished(): void
    {
        $this->safely(fn () => $this->cache->decrement(self::PREFIX.'in_flight'));
    }

    /**
     * @return array{counts: array<string, int>, duration_ms_total: int, last_duration_ms: int|null, in_flight: int}
     */
    public function snapshot(): array
    {
        $counts = [];
        foreach (self::OUTCOMES as $outcome) {
            $counts[$outcome] = (int) $this->safely(fn () => $this->cache->get(self::PREFIX.'count:'.$outcome, 0));
        }
        $last = $this->safely(fn () => $this->cache->get(self::PREFIX.'last_duration_ms'));

        return [
            'counts' => $counts,
            'duration_ms_total' => (int) $this->safely(fn () => $this->cache->get(self::PREFIX.'duration_ms_total', 0)),
            'last_duration_ms' => is_numeric($last) ? (int) $last : null,
            'in_flight' => max(0, (int) $this->safely(fn () => $this->cache->get(self::PREFIX.'in_flight', 0))),
        ];
    }

    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
