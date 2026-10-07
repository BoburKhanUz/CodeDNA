import type { FocusCriterion, FocusExclusion, RoadmapStatus, RoadmapStepType } from "@/lib/api/types";
import { competencyName } from "@/lib/challenge/format";

/** Display helpers for learning roadmaps. Labels only; nothing is computed. */

const STATUS: Record<RoadmapStatus, string> = {
  ACTIVE: "Active",
  COMPLETED: "All steps done",
  SUPERSEDED: "Superseded",
};

const STEP_TYPE: Record<RoadmapStepType, string> = {
  READ: "Understand",
  PRACTICE: "Practise",
  CHALLENGE: "Challenge",
  REASSESS: "Re-assess",
};

const EXCLUSION: Record<FocusExclusion, string> = {
  NO_GAP: "No material gap: nothing to work on.",
  INSUFFICIENT_EVIDENCE: "Not enough evidence: no learning need can be established.",
  UNSUPPORTED: "The analyzer cannot measure this competency in the analyzed languages.",
  MISSING: "The evidence is not available.",
  NOT_TARGETED: "The target profile sets no target for this competency.",
  NO_TRACK: "There is no learning track for this competency yet.",
  TRACK_LIMIT: "A material gap, kept for later: a roadmap focuses on at most three competencies at a time.",
};

const CRITERION: Record<FocusCriterion, string> = {
  PRIORITY: "a higher gap priority",
  RAW_GAP: "a larger gap",
  EVIDENCE_QUALITY: "better evidence quality",
  COMPETENCY_KEY: "an equal gap, ordered by name",
};

export const roadmapStatusLabel = (status: RoadmapStatus): string => STATUS[status];
export const stepTypeLabel = (type: RoadmapStepType): string => STEP_TYPE[type];
export const exclusionLabel = (reason: FocusExclusion | undefined): string => (reason ? EXCLUSION[reason] : "");

/** Why a focus entry ranks where it does, from the stored deciding criterion. */
export function rankExplanation(rank: number | undefined, criterion: FocusCriterion | null | undefined, above: string | null | undefined): string {
  if (rank === undefined) return "";
  if (criterion == null || above == null) return rank === 1 ? "It is the only actionable gap." : "It is the last actionable gap in order.";
  return `Ranked above ${competencyName(above)} because of ${CRITERION[criterion]}.`;
}

/** 75 -> "1 h 15 min", 45 -> "45 min". */
export function formatMinutes(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (hours === 0) return `${rest} min`;
  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}
