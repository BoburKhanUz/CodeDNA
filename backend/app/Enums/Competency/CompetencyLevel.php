<?php

declare(strict_types=1);

namespace App\Enums\Competency;

/**
 * Evidence levels of an assessed competency, lowest first. They describe how
 * strongly the analyzed source code meets the competency's measurable
 * criteria. They are not seniority, career or employment levels, and they
 * say nothing about a person.
 */
enum CompetencyLevel: string
{
    case NotEstablished = 'NOT_ESTABLISHED';
    case Developing = 'DEVELOPING';
    case Established = 'ESTABLISHED';
    case Strong = 'STRONG';

    public function ordinal(): int
    {
        return match ($this) {
            self::NotEstablished => 0,
            self::Developing => 1,
            self::Established => 2,
            self::Strong => 3,
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::NotEstablished => 'Not established',
            self::Developing => 'Developing',
            self::Established => 'Established',
            self::Strong => 'Strong',
        };
    }
}
