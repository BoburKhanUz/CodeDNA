<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Enums\Growth\GrowthFailure;
use RuntimeException;

final class GrowthException extends RuntimeException
{
    public function __construct(public readonly GrowthFailure $failure)
    {
        parent::__construct($failure->value);
    }
}
