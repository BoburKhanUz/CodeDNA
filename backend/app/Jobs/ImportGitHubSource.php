<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\GitHub\RunGitHubImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one GitHub import (Phase 19). The payload is the import ID only: no
 * token, URL, repository name or branch is ever serialized into the queue.
 * One attempt; GitHub failures are recorded on the import, and the owner
 * can import again. A worker timeout or crash marks the import FAILED.
 */
final class ImportGitHubSource implements ShouldQueue
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

    public function handle(RunGitHubImport $import): void
    {
        $import->handle($this->importId);
    }

    public function failed(?Throwable $e): void
    {
        app(RunGitHubImport::class)->abandon($this->importId);
    }
}
