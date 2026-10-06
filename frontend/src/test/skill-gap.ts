import type { SkillGapResult, SkillGapSnapshot, SkillGapSnapshotSummary } from "@/lib/api/types";

import { COMPETENCY_ID } from "./competency";
import { DNA_ID, RUN_ID, SOURCE_ID } from "./dna";
import { project } from "./responses";

/**
 * Skill gap responses shaped exactly like the Laravel API's
 * (SkillGapSnapshotResource). Values are those skill gap 1.0.0 produces for
 * the competency matrix of 40 functions, 10 types and 10 files (backend
 * tests/Feature/SkillGap/SkillGapApiTest.php).
 */

export const SKILL_GAP_ID = "01k6p0a1b2c3d4e5f6g7h8j9sg";

function result(overrides: Partial<SkillGapResult> & Pick<SkillGapResult, "competency_key" | "name">): SkillGapResult {
  return {
    status: "NO_GAP",
    current_score: "0.7500",
    target_score: "0.7500",
    raw_gap: "0.0000",
    material_gap: false,
    priority: null,
    priority_capped: null,
    evidence_quality: "0.9000",
    competency_status: "ASSESSED",
    current_level: "ESTABLISHED",
    target_rationale: null,
    limitations: [],
    evidence: [],
    ...overrides,
  };
}

const complexity = result({
  competency_key: "COMPLEXITY_MANAGEMENT",
  name: "Complexity management",
  target_rationale: "Inside the ESTABLISHED band: average and concentrated branching close to the best thresholds.",
  evidence: [
    { source: "COMPLEXITY.mean_cyclomatic_complexity", status: "AVAILABLE", value: "4.0000", score: "0.7500" },
    { source: "COMPLEXITY.complex_function_share", status: "AVAILABLE", value: "0.0500", score: "0.7500" },
  ],
});
const design = result({ competency_key: "FUNCTION_DESIGN", name: "Function design", current_score: "0.9000", current_level: "STRONG" });
const types = result({ competency_key: "TYPE_STRUCTURE", name: "Type structure", current_score: "1.0000", current_level: "STRONG" });
const hygiene = result({
  competency_key: "CODE_HYGIENE",
  name: "Code hygiene",
  status: "GAP",
  current_score: "0.6000",
  target_score: "0.9000",
  raw_gap: "0.3000",
  material_gap: true,
  priority: "HIGH",
  priority_capped: false,
  current_level: "DEVELOPING",
  target_rationale: "Syntax validity is expected of analyzable code: 0.90 allows about 2.5% of files with syntax errors.",
  evidence: [{ source: "CODE_HYGIENE.syntax_error_share", status: "AVAILABLE", value: "0.1000", score: "0.6000" }],
});

export const gapsSnapshot: SkillGapSnapshot = {
  id: SKILL_GAP_ID,
  type: "skill_gap_snapshot",
  project_id: project.id,
  competency_snapshot_id: COMPETENCY_ID,
  dna_snapshot_id: DNA_ID,
  analysis_run_id: RUN_ID,
  source_snapshot_id: SOURCE_ID,
  status: "GAPS_IDENTIFIED",
  skill_gap_version: "1.0.0",
  specification_fingerprint: "2dbc9aad5c196c26d02731c752afee32ba19efdc1ab7d54be69a190ec7331e13",
  target_profile: {
    key: "ENGINEERING_STANDARD",
    version: "1.0.0",
    description: "A defined, measurable engineering standard for the analyzed code. Not a job title or seniority level.",
  },
  thresholds: {
    material_gap: "0.0500",
    priorities: [
      { priority: "LOW", minimum_gap: "0.0500" },
      { priority: "MEDIUM", minimum_gap: "0.1500" },
      { priority: "HIGH", minimum_gap: "0.3000" },
    ],
    high_priority_minimum_evidence_quality: "0.6000",
  },
  competency_version: "1.0.0",
  dna_scoring_version: "1.0.0",
  created_at: "2026-10-12T08:00:02Z",
  summary: {
    competencies: 4,
    material_gaps: 1,
    statuses: { GAP: 1, NO_GAP: 3, INSUFFICIENT_EVIDENCE: 0, UNSUPPORTED: 0, MISSING: 0, NOT_TARGETED: 0 },
    priorities: { LOW: 0, MEDIUM: 0, HIGH: 1 },
  },
  languages: ["php", "python"],
  competency_snapshot: {
    id: COMPETENCY_ID,
    status: "ASSESSED",
    specification_fingerprint: "f9019e29ed358920eb9e12856aecc9f07450c6466ce174b2dc191c7a64f29eac",
    created_at: "2026-10-12T08:00:01Z",
  },
  source_snapshot: { id: SOURCE_ID, version: 1, file_count: 12, primary_language: "php", created_at: "2026-10-12T07:55:00Z" },
  results: [complexity, design, types, hygiene],
};

/** All measured competencies at or above target (one immaterial 0.04 gap kept). */
export const noGapsSnapshot: SkillGapSnapshot = {
  ...gapsSnapshot,
  status: "NO_MATERIAL_GAPS",
  summary: {
    competencies: 4,
    material_gaps: 0,
    statuses: { GAP: 0, NO_GAP: 3, INSUFFICIENT_EVIDENCE: 1, UNSUPPORTED: 0, MISSING: 0, NOT_TARGETED: 0 },
    priorities: { LOW: 0, MEDIUM: 0, HIGH: 0 },
  },
  results: [
    complexity,
    design,
    { ...types, status: "INSUFFICIENT_EVIDENCE", current_score: null, raw_gap: null, material_gap: null, competency_status: "INSUFFICIENT_EVIDENCE", current_level: null, evidence_quality: "0.3484" },
    { ...hygiene, status: "NO_GAP", current_score: "0.8600", raw_gap: "0.0400", material_gap: false, priority: null, priority_capped: null, current_level: "STRONG" },
  ],
};

/** Nothing measurable: insufficient and unsupported evidence. */
export const insufficientSnapshot: SkillGapSnapshot = {
  ...gapsSnapshot,
  status: "INSUFFICIENT_DATA",
  summary: {
    competencies: 4,
    material_gaps: 0,
    statuses: { GAP: 0, NO_GAP: 0, INSUFFICIENT_EVIDENCE: 3, UNSUPPORTED: 1, MISSING: 0, NOT_TARGETED: 0 },
    priorities: { LOW: 0, MEDIUM: 0, HIGH: 0 },
  },
  results: gapsSnapshot.results.map((r, i) => ({
    ...r,
    status: i === 3 ? ("UNSUPPORTED" as const) : ("INSUFFICIENT_EVIDENCE" as const),
    current_score: null,
    raw_gap: null,
    material_gap: null,
    priority: null,
    priority_capped: null,
    current_level: null,
    competency_status: i === 3 ? ("UNSUPPORTED" as const) : ("INSUFFICIENT_EVIDENCE" as const),
  })),
};

export function skillGapSummary(snapshot: SkillGapSnapshot): SkillGapSnapshotSummary {
  return {
    id: snapshot.id,
    type: "skill_gap_snapshot",
    project_id: snapshot.project_id,
    competency_snapshot_id: snapshot.competency_snapshot_id,
    dna_snapshot_id: snapshot.dna_snapshot_id,
    analysis_run_id: snapshot.analysis_run_id,
    source_snapshot_id: snapshot.source_snapshot_id,
    status: snapshot.status,
    skill_gap_version: snapshot.skill_gap_version,
    target_profile: { key: snapshot.target_profile.key, version: snapshot.target_profile.version },
    competency_version: snapshot.competency_version,
    summary: snapshot.summary,
    created_at: snapshot.created_at,
  };
}
