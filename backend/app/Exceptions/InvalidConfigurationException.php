<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when required configuration is missing or unsafe, so a
 * misconfigured deployment fails immediately and clearly.
 */
final class InvalidConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public static function withProblems(array $problems): self
    {
        return new self("Invalid CodeDNA configuration:\n - ".implode("\n - ", $problems));
    }
}
