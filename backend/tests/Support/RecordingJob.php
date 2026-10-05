<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Test-only job: records that it ran by writing a marker to the Redis cache.
 */
final class RecordingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $marker) {}

    public function handle(): void
    {
        Cache::store('redis')->put($this->marker, 'handled', 60);
    }
}
