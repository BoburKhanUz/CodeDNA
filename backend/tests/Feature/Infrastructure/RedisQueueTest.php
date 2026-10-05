<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\RecordingJob;
use Tests\TestCase;

final class RedisQueueTest extends TestCase
{
    public function test_a_job_is_dispatched_executed_and_completed_through_redis(): void
    {
        $queueName = 'codedna-test-'.Str::random(8);
        $marker = 'codedna-test:job:'.Str::random(12);
        $redisQueue = Queue::connection('redis');

        $redisQueue->pushOn($queueName, new RecordingJob($marker));
        $this->assertSame(1, $redisQueue->size($queueName), 'the job is waiting in Redis');

        $this->artisan('queue:work', [
            'connection' => 'redis',
            '--queue' => $queueName,
            '--once' => true,
            '--stop-when-empty' => true,
        ])->assertSuccessful();

        $this->assertSame(0, $redisQueue->size($queueName), 'the job left the queue');
        $this->assertSame('handled', Cache::store('redis')->pull($marker), 'the job ran');
    }
}
