<?php

declare(strict_types=1);

namespace App\Actions\Insights;

use App\Models\AiInsight;

final readonly class RequestedInsight
{
    public function __construct(public AiInsight $insight, public bool $created) {}
}
