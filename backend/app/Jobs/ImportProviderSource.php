<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Repositories\RunProviderImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Imports one GitLab or Bitbucket branch into a source snapshot (Phase 28).
 * The payload is the import ID only: no token, URL or repository data.
 * Shares the GitHub import queue, timeout and single attempt; a worker
 * timeout or crash marks the import FAILED.
 */
final class ImportProviderSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $importId)
    {
        $this->onConnection((string) config('codedna.github.queue_connection'));
        $this->onQueue((string) config('codedna.github.queue'));
        $this->timeout = (int) config('codedna.github.job_timeout_seconds');
    }

    public function handle(RunProviderImport $import): void
    {
        $import->handle($this->importId);
    }

    public function failed(?Throwable $e): void
    {
        app(RunProviderImport::class)->abandon($this->importId);
    }
}
