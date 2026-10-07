import type { GrowthEventKind, GrowthMetricType, GrowthStatus } from "@/lib/api/types";
import { competencyName } from "@/lib/challenge/format";
import { levelLabel } from "@/lib/competency/format";
import { formatScore } from "@/lib/dna/format";
import { skillGapStatusLabel } from "@/lib/skill-gap/format";

/**
 * Display helpers for growth. Labels and digit-based reformatting only:
 * every delta, status and transition comes from the server, and nothing is
 * compared or computed in the browser.
 */

const STATUS: Record<GrowthStatus, string> = {
  IMPROVED: "Improved",
  REGRESSED: "Regressed",
  UNCHANGED: "No meaningful change",
  INSUFFICIENT_EVIDENCE: "Insufficient evidence",
};

const DNA_METRICS: Record<string, string> = {
  OVERALL: "CodeDNA overall score",
  STRUCTURE: "Structure",
  COMPLEXITY: "Complexity",
  CODE_HYGIENE: "Code hygiene",
};

const TYPES: Record<GrowthMetricType, string> = { DNA: "CodeDNA", COMPETENCY: "Competencies", SKILL_GAP: "Skill gaps" };

const VERSION_FIELDS: Record<string, string> = {
  dna_scoring_version: "DNA scoring version",
  dna_specification_fingerprint: "DNA scoring specification",
  metrics_version: "Analyzer metrics version",
  competency_version: "Competency version",
  competency_specification_fingerprint: "Competency specification",
  skill_gap_version: "Skill gap version",
  skill_gap_specification_fingerprint: "Skill gap specification",
  target_profile: "Target profile",
  target_profile_version: "Target profile version",
};

const STATES: Record<string, string> = {
  SCORED: "Scored",
  READY: "Scored",
  UNAVAILABLE: "Unavailable",
  INSUFFICIENT_DATA: "Insufficient data",
  ASSESSED: "Assessed",
  MISSING: "Not present",
};

export const growthStatusLabel = (status: GrowthStatus): string => STATUS[status];
export const metricTypeLabel = (type: GrowthMetricType): string => TYPES[type];
export const versionFieldLabel = (field: string): string => VERSION_FIELDS[field] ?? field;

export function metricName(type: GrowthMetricType, key: string): string {
  return type === "DNA" ? (DNA_METRICS[key] ?? key) : competencyName(key);
}

/** A measurement state, in the words its own page uses. */
export function stateLabel(type: GrowthMetricType, state: string): string {
  if (type === "SKILL_GAP" && state !== "MISSING") return skillGapStatusLabel(state);
  return STATES[state] ?? skillGapStatusLabel(state);
}

/** A signed 0–1 delta in points of 100: "0.1900" -> "+19.00", "-0.0040" -> "−0.40", "0.0000" -> "0.00". */
export function formatDelta(delta: string | null): string | null {
  if (delta === null) return null;
  const negative = delta.startsWith("-");
  const score = formatScore(negative ? delta.slice(1) : delta);
  if (score === null) return null;
  if (/^0\.0+$/.test(score)) return score;
  return `${negative ? "−" : "+"}${score}`;
}

const AREA: Record<GrowthMetricType, string> = { DNA: "CodeDNA", COMPETENCY: "Competency", SKILL_GAP: "Skill gap" };

/** One event as a sentence about the measured code, never about a person or a cause. */
export function eventLabel(kind: GrowthEventKind, type: GrowthMetricType, key: string, previousLevel?: string | null, currentLevel?: string | null): string {
  // The area keeps same-named metrics apart (the Code hygiene dimension, competency and gap).
  const name = `${AREA[type]} · ${metricName(type, key)}`;
  switch (kind) {
    case "GAP_CLOSED":
      return `${name}: the material gap closed`;
    case "GAP_OPENED":
      return `${name}: a material gap opened`;
    case "LEVEL_UP":
    case "LEVEL_DOWN":
      return `${name}: level ${kind === "LEVEL_UP" ? "rose" : "fell"} from ${levelLabel(previousLevel ?? null) ?? "—"} to ${levelLabel(currentLevel ?? null) ?? "—"}`;
    case "IMPROVED":
      return `${name}: improved`;
    case "REGRESSED":
      return `${name}: regressed`;
  }
}
