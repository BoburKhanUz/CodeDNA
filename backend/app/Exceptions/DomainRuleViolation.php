<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\AnalysisRunStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A domain invariant was about to be broken (programming error, not user
 * input): changing an immutable historical record, an invalid state
 * transition, or creating a record whose prerequisites are not met.
 */
final class DomainRuleViolation extends LogicException
{
    public static function immutable(Model $model, string $operation): self
    {
        return new self(sprintf('%s %s is an immutable historical record and cannot be %s.', class_basename($model), $model->getKey(), $operation));
    }

    public static function invalidTransition(AnalysisRunStatus $from, AnalysisRunStatus $to): self
    {
        return new self("Analysis run cannot move from {$from->value} to {$to->value}.");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
