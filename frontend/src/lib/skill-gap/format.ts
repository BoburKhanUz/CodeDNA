import type { GapPriority, SkillGapStatus } from "@/lib/api/types";

/**
 * Labels for skill gap values. Numbers are formatted with lib/dna/format
 * (digit-based); no gap, priority or target is computed here. Wording is
 * about the analyzed code and the target, never about a person.
 */

const STATUS_LABELS: Record<SkillGapStatus, string> = {
  GAP: "Material gap",
  NO_GAP: "No material gap",
  INSUFFICIENT_EVIDENCE: "Insufficient evidence",
  UNSUPPORTED: "Unsupported evidence",
  MISSING: "Evidence unavailable",
  NOT_TARGETED: "Not targeted",
};

const UNMEASURED_EXPLANATIONS: Partial<Record<SkillGapStatus, string>> = {
  INSUFFICIENT_EVIDENCE: "Insufficient evidence to determine this competency gap.",
  UNSUPPORTED: "The analyzer cannot measure the evidence for this competency in the analyzed languages, so no gap is determined.",
  MISSING: "The competency evidence is not available, so no gap is determined.",
  NOT_TARGETED: "The target profile defines no target for this competency.",
};

const PRIORITY_LABELS: Record<GapPriority, string> = { LOW: "Low priority", MEDIUM: "Medium priority", HIGH: "High priority" };

export function skillGapStatusLabel(status: string | null): string {
  return status !== null && status in STATUS_LABELS ? STATUS_LABELS[status as SkillGapStatus] : "Unknown";
}

export function unmeasuredExplanation(status: string | null): string | null {
  return status !== null ? (UNMEASURED_EXPLANATIONS[status as SkillGapStatus] ?? null) : null;
}

export function priorityLabel(priority: string | null): string | null {
  return priority !== null && priority in PRIORITY_LABELS ? PRIORITY_LABELS[priority as GapPriority] : null;
}

/** "ENGINEERING_STANDARD" -> "Engineering standard". */
export function targetProfileLabel(key: string): string {
  const words = key.toLowerCase().replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}
