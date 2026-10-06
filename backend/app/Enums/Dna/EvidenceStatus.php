<?php

declare(strict_types=1);

namespace App\Enums\Dna;

/**
 * Availability of one scoring component's evidence. These are never
 * conflated (docs/architecture/dna-scoring-v1.md#missing-data):
 *
 * - AVAILABLE: every input is an integer and the denominator reaches the
 *   component's minimum; the component is scored (a measured 0 is evidence);
 * - INSUFFICIENT_EVIDENCE: the inputs exist but the denominator is below the
 *   minimum (e.g. fewer than 5 functions): too little code to rate;
 * - UNSUPPORTED: an input is null and listed in the metrics' `unsupported`
 *   array: the analyzer cannot measure it for these languages;
 * - MISSING: an input is absent, or null without being declared unsupported.
 */
enum EvidenceStatus: string
{
    case Available = 'AVAILABLE';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case Unsupported = 'UNSUPPORTED';
    case Missing = 'MISSING';
}
