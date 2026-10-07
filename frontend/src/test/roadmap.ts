import type { FocusEntry, Roadmap, RoadmapStep, RoadmapSummary, RoadmapTrack } from "@/lib/api/types";

import { COMPETENCY_ID } from "./competency";
import { DNA_ID, RUN_ID, SOURCE_ID } from "./dna";
import { project } from "./responses";
import { SKILL_GAP_ID } from "./skill-gap";

/** Roadmap responses shaped exactly like the Laravel API's (RoadmapResource, RoadmapSummaryResource). */

export const ROADMAP_ID = "01k6p0a1b2c3d4e5f6g7h8j9rm";
export const OLD_ROADMAP_ID = "01k6p0a1b2c3d4e5f6g7h8j9ro";

const NOTICE = "Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.";

const hygieneFocus: FocusEntry = {
  competency_key: "CODE_HYGIENE",
  status: "GAP",
  priority: "HIGH",
  priority_capped: false,
  current_score: "0.6000",
  target_score: "0.9000",
  raw_gap: "0.3000",
  evidence_quality: "0.9000",
  current_level: "DEVELOPING",
  rank: 1,
  ranked_above: "FUNCTION_DESIGN",
  deciding_criterion: "PRIORITY",
};

const designFocus: FocusEntry = {
  competency_key: "FUNCTION_DESIGN",
  status: "GAP",
  priority: "MEDIUM",
  priority_capped: true,
  current_score: "0.4000",
  target_score: "0.7500",
  raw_gap: "0.3500",
  evidence_quality: "0.5500",
  current_level: "DEVELOPING",
  rank: 2,
  ranked_above: null,
  deciding_criterion: null,
};

export function step(overrides: Partial<RoadmapStep> & Pick<RoadmapStep, "key" | "position">): RoadmapStep {
  return {
    type: "PRACTICE",
    title: `Step ${overrides.key}`,
    description: `Do ${overrides.key}.`,
    objective: `Goal of ${overrides.key}.`,
    estimated_minutes: 15,
    prerequisites: [],
    completed_at: null,
    can_complete: false,
    challenge: null,
    practice: null,
    ...overrides,
  };
}

const hygieneTrack: RoadmapTrack = {
  position: 1,
  key: "CODE_HYGIENE",
  version: "1.0.0",
  competency_key: "CODE_HYGIENE",
  title: "Keep source code parseable",
  description: "Make sure every analyzed file is valid source code.",
  objective: "No files with syntax errors in the analyzed source.",
  estimated_minutes: 110,
  focus: hygieneFocus,
  progress: { completed: 0, total: 4 },
  steps: [
    step({ key: "ch-syntax", position: 1, type: "READ", title: "Understand why syntax errors matter", can_complete: true }),
    step({ key: "ch-fix", position: 2, title: "Fix invalid syntax", prerequisites: ["ch-syntax"] }),
    step({
      key: "ch-challenge",
      position: 3,
      type: "CHALLENGE",
      title: "Complete a code hygiene challenge",
      prerequisites: ["ch-fix"],
      challenge: { key: "CODE_HYGIENE_001", version: "1.0.0", title: "Repair the configuration parser", difficulty: "BEGINNER", in_catalog: true },
    }),
    step({ key: "ch-reassess", position: 4, type: "REASSESS", title: "Re-assess with a new analysis", prerequisites: ["ch-syntax", "ch-fix", "ch-challenge"] }),
  ],
};

const designTrack: RoadmapTrack = {
  position: 2,
  key: "FUNCTION_DESIGN",
  version: "1.0.0",
  competency_key: "FUNCTION_DESIGN",
  title: "Design focused functions",
  description: "Keep functions short, with few parameters and shallow nesting.",
  objective: "Fewer long functions.",
  estimated_minutes: 75,
  focus: designFocus,
  progress: { completed: 0, total: 2 },
  steps: [
    step({ key: "fd-responsibility", position: 5, type: "READ", title: "Understand single responsibility for functions", can_complete: true }),
    step({ key: "fd-reassess", position: 6, type: "REASSESS", title: "Re-assess design", prerequisites: ["fd-responsibility"], estimated_minutes: 60 }),
  ],
};

/** A fresh copy every time, so that tests can change it. */
export function roadmap(overrides: Partial<Roadmap> = {}): Roadmap {
  return structuredClone({
    id: ROADMAP_ID,
    type: "learning_roadmap",
    project_id: project.id,
    skill_gap_snapshot_id: SKILL_GAP_ID,
    status: "ACTIVE",
    focus: ["CODE_HYGIENE", "FUNCTION_DESIGN"],
    progress: { completed: 0, total: 6 },
    estimated_minutes: 185,
    versions: { roadmap: "1.0.0", rules: "1.0.0", skill_gap: "1.0.0", target_profile: { key: "ENGINEERING_STANDARD", version: "1.0.0" }, challenge_catalog: "1.0.0" },
    created_at: "2026-10-14T08:00:00Z",
    superseded_at: null,
    completed_at: null,
    notice: NOTICE,
    development_focus: {
      selected: [hygieneFocus, designFocus],
      excluded: [
        { ...designFocus, competency_key: "COMPLEXITY_MANAGEMENT", status: "INSUFFICIENT_EVIDENCE", priority: null, priority_capped: null, current_score: null, raw_gap: null, current_level: null, rank: undefined, reason: "INSUFFICIENT_EVIDENCE" },
        { ...designFocus, competency_key: "TYPE_STRUCTURE", status: "NO_GAP", priority: null, priority_capped: null, raw_gap: "0.0000", rank: undefined, reason: "NO_GAP" },
      ],
    },
    tracks: [hygieneTrack, designTrack],
    lineage: { skill_gap_snapshot_id: SKILL_GAP_ID, competency_snapshot_id: COMPETENCY_ID, dna_snapshot_id: DNA_ID, analysis_run_id: RUN_ID, source_snapshot_id: SOURCE_ID },
    fingerprints: { roadmap: "f".repeat(64), catalog: "a".repeat(64), rules: "b".repeat(64), skill_gap_specification: "c".repeat(64), challenge_catalog: "d".repeat(64) },
    current: { catalog: true, rules: true },
    superseded_by: null,
    updated_at: "2026-10-14T08:00:00Z",
    ...overrides,
  });
}

export function summaryOf(r: Roadmap): RoadmapSummary {
  const { id, type, project_id, skill_gap_snapshot_id, status, focus, progress, estimated_minutes, created_at, superseded_at, completed_at } = r;
  return { id, type, project_id, skill_gap_snapshot_id, status, focus, progress, estimated_minutes, versions: { roadmap: r.versions.roadmap, rules: r.versions.rules }, created_at, superseded_at, completed_at };
}

/** The roadmap after "ch-syntax" was completed. */
export function afterFirstStep(): Roadmap {
  const base = roadmap();
  const [first, second, ...rest] = base.tracks[0].steps;
  return {
    ...base,
    progress: { completed: 1, total: 6 },
    tracks: [
      { ...base.tracks[0], progress: { completed: 1, total: 4 }, steps: [{ ...first, completed_at: "2026-10-14T09:00:00Z", can_complete: false }, { ...second, can_complete: true }, ...rest] },
      base.tracks[1],
    ],
  };
}
