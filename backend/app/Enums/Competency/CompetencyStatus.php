<?php

declare(strict_types=1);

namespace App\Enums\Competency;

/**
 * ASSESSED: every required piece of evidence is available; the competency
 * has a score and a level. Otherwise the status names why it was not
 * assessed (no score, no level, never a score of 0):
 *
 * - INSUFFICIENT_EVIDENCE: too little code to rate (below a minimum count);
 * - UNSUPPORTED: the analyzer cannot measure required evidence for the
 *   analyzed languages;
 * - MISSING: required evidence is absent from the DNA snapshot.
 *
 * When several apply, UNSUPPORTED wins over MISSING, which wins over
 * INSUFFICIENT_EVIDENCE.
 */
enum CompetencyStatus: string
{
    case Assessed = 'ASSESSED';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case Unsupported = 'UNSUPPORTED';
    case Missing = 'MISSING';
}
