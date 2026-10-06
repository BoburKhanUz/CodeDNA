import type { CompetencyLevel, CompetencyStatus } from "@/lib/api/types";

/**
 * Labels for competency values. Scores and evidence quality are formatted
 * with lib/dna/format (digit-based, no arithmetic); nothing is computed here.
 * Levels are evidence levels of the analyzed code, never seniority labels.
 */

const LEVEL_LABELS: Record<CompetencyLevel, string> = {
  NOT_ESTABLISHED: "Not established",
  DEVELOPING: "Developing",
  ESTABLISHED: "Established",
  STRONG: "Strong",
};

const LEVEL_ORDINALS: Record<CompetencyLevel, number> = { NOT_ESTABLISHED: 0, DEVELOPING: 1, ESTABLISHED: 2, STRONG: 3 };

export function levelLabel(level: string | null): string | null {
  return level !== null && level in LEVEL_LABELS ? LEVEL_LABELS[level as CompetencyLevel] : null;
}

/** "Level 2 · Established". */
export function levelBadge(level: string | null): string | null {
  const label = levelLabel(level);
  return label === null ? null : `Level ${LEVEL_ORDINALS[level as CompetencyLevel]} · ${label}`;
}

/** Neutral statements about the code's evidence for each level (never about a person). */
const LEVEL_MEANINGS: Record<CompetencyLevel, string> = {
  NOT_ESTABLISHED: "The analyzed code does not meet the defined criteria for this competency.",
  DEVELOPING: "The analyzed code meets the defined criteria in part.",
  ESTABLISHED: "Available source-code evidence meets the defined threshold for this competency.",
  STRONG: "The analyzed code demonstrates strong evidence for this competency.",
};

export function levelMeaning(level: string | null): string | null {
  return level !== null && level in LEVEL_MEANINGS ? LEVEL_MEANINGS[level as CompetencyLevel] : null;
}

const STATUS_LABELS: Record<CompetencyStatus, string> = {
  ASSESSED: "Assessed",
  INSUFFICIENT_EVIDENCE: "Insufficient evidence",
  UNSUPPORTED: "Unsupported evidence",
  MISSING: "Evidence unavailable",
};

const STATUS_EXPLANATIONS: Record<Exclude<CompetencyStatus, "ASSESSED">, string> = {
  INSUFFICIENT_EVIDENCE: "The analyzed code is too small to rate this competency (below a minimum count). This is not a low result.",
  UNSUPPORTED: "The analyzer cannot measure required evidence for the analyzed languages. This is not a low result.",
  MISSING: "Required evidence is not present in the CodeDNA assessment. This is not a low result.",
};

export function competencyStatusLabel(status: string | null): string {
  return status !== null && status in STATUS_LABELS ? STATUS_LABELS[status as CompetencyStatus] : "Unknown";
}

export function competencyStatusExplanation(status: string | null): string | null {
  return status !== null && status in STATUS_EXPLANATIONS ? STATUS_EXPLANATIONS[status as Exclude<CompetencyStatus, "ASSESSED">] : null;
}
