<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The result of a cheap runtime check (no generation): whether the service
 * answered and whether the configured model is available. $error is a fixed
 * identifier, never a response body.
 */
final readonly class ModelHealth
{
    public function __construct(
        public bool $reachable,
        public ?bool $modelAvailable,
        public ?string $runtimeVersion = null,
        public ?string $error = null,
    ) {}

    /** @return array{reachable: bool, model_available: bool|null, runtime_version: string|null, error: string|null} */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'model_available' => $this->modelAvailable,
            'runtime_version' => $this->runtimeVersion,
            'error' => $this->error,
        ];
    }
}
