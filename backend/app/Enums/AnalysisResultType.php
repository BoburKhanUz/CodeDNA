<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which analyzer result an analysis run asks for (internal analyzer
 * contract, options.result_type). The analyzer's default is Foundation.
 *
 *     FOUNDATION       source intake and file inventory (Phase 08, IR 1.0)
 *        ↓
 *     STATIC_ANALYSIS  + parsing, IR 1.1, static metrics, findings (Phase 09)
 *        ↓
 *     (DNA scoring, Phase 11: not a result type yet)
 */
enum AnalysisResultType: string
{
    case Foundation = 'foundation';
    case StaticAnalysis = 'static_analysis';

    public static function default(): self
    {
        return self::Foundation;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
