<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Models\RepositoryProviderImport;

/** The import a request produced, and whether it was newly queued (Phase 28). */
final readonly class RequestedProviderImport
{
    public function __construct(public RepositoryProviderImport $import, public bool $created) {}
}
