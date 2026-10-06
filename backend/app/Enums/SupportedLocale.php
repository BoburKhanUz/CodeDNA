<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Interface languages a developer may choose (docs/architecture/data-model.md#developer_profiles).
 *
 * Stored as a preference only: the UI is not translated yet. The database
 * checks the format; this enum decides which values are offered.
 */
enum SupportedLocale: string
{
    case English = 'en';
    case Uzbek = 'uz';
    case Russian = 'ru';
}
