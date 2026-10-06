<?php

declare(strict_types=1);

namespace App\Services\SkillGap;

use App\Enums\SkillGap\SkillGapFailure;
use RuntimeException;

/**
 * A competency snapshot that cannot be analyzed. The message is the
 * failure's safe message.
 */
final class SkillGapException extends RuntimeException
{
    public function __construct(public readonly SkillGapFailure $failure)
    {
        parent::__construct($failure->message());
    }
}
