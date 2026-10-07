import type { GrowthObservation, GrowthOverview, GrowthSnapshot, GrowthSnapshotSummary, GrowthSummary } from "@/lib/api/types";
import { project } from "@/test/responses";

/** Growth responses shaped exactly like GrowthController's and GrowthSnapshot(Summary)Resource's. */

export const GROWTH_ID = "01k6r0a1b2c3d4e5f6g7h8j9gr";
export const OLD_GROWTH_ID = "01k6r0a1b2c3d4e5f6g7h8j9go";
export const NOTICE =
  "Growth compares deterministic code assessments only. Completed learning steps and challenges are not growth evidence; only a new code analysis can show change.";

const versions = {
  dna_scoring_version: "1.0.0",
  dna_specification_fingerprint: "a".repeat(64),
  metrics_version: "1.1.0",
  competency_version: "1.0.0",
  competency_specification_fingerprint: "b".repeat(64),
  skill_gap_version: "1.0.0",
  skill_gap_specification_fingerprint: "c".repeat(64),
  target_profile: "ENGINEERING_STANDARD",
  target_profile_version: "1.0.0",
};

const ref = (n: string) => ({
  skill_gap_snapshot_id: `01k6q0a1b2c3d4e5f6g7h8j9${n}s`,
  competency_snapshot_id: `01k6q0a1b2c3d4e5f6g7h8j9${n}c`,
  dna_snapshot_id: `01k6q0a1b2c3d4e5f6g7h8j9${n}d`,
  analysis_run_id: `01k6q0a1b2c3d4e5f6g7h8j9${n}r`,
  source_snapshot_id: `01k6q0a1b2c3d4e5f6g7h8j9${n}x`,
});

export function observation(overrides: Partial<GrowthObservation> = {}): GrowthObservation {
  return {
    metric_key: "OVERALL",
    better: "HIGHER",
    previous_state: "READY",
    current_state: "READY",
    previous_value: "0.3050",
    current_value: "0.8050",
    delta: "0.5000",
    previous_level: null,
    current_level: null,
    level_change: null,
    previous_evidence_quality: "0.9000",
    current_evidence_quality: "0.9000",
    status: "IMPROVED",
    ...overrides,
  };
}

function emptyStatuses(): GrowthSummary["statuses"] {
  const zero = { IMPROVED: 0, REGRESSED: 0, UNCHANGED: 0, INSUFFICIENT_EVIDENCE: 0 };
  return { DNA: { ...zero }, COMPETENCY: { ...zero }, SKILL_GAP: { ...zero } };
}

/** The summary the server would store for these observations. */
function summarize(snapshot: Pick<GrowthSnapshot, "dna" | "competencies" | "skill_gaps">): GrowthSummary {
  const statuses = emptyStatuses();
  const levels = { UP: 0, DOWN: 0 };
  const groups = [["DNA", snapshot.dna], ["COMPETENCY", snapshot.competencies], ["SKILL_GAP", snapshot.skill_gaps]] as const;
  for (const [type, list] of groups) {
    for (const o of list) {
      statuses[type][o.status] += 1;
      if (o.level_change === "UP" || o.level_change === "DOWN") levels[o.level_change] += 1;
    }
  }
  return { observations: snapshot.dna.length + snapshot.competencies.length + snapshot.skill_gaps.length, statuses, level_changes: levels };
}

/** A COMPARED snapshot: overall improved, one gap closed, one level up, one unmeasured competency. */
export function compared(overrides: Partial<GrowthSnapshot> = {}): GrowthSnapshot {
  const body = {
    dna: [
      observation(),
      observation({ metric_key: "COMPLEXITY", previous_state: "SCORED", current_state: "SCORED", previous_value: "0.7000", current_value: "0.7200", delta: "0.0200", status: "UNCHANGED" }),
      observation({ metric_key: "STRUCTURE", previous_state: "UNAVAILABLE", current_state: "SCORED", previous_value: null, current_value: null, delta: null, status: "INSUFFICIENT_EVIDENCE" }),
    ],
    competencies: [
      observation({ metric_key: "FUNCTION_DESIGN", previous_state: "ASSESSED", current_state: "ASSESSED", previous_value: "0.4800", current_value: "0.6700", delta: "0.1900", previous_level: "DEVELOPING", current_level: "ESTABLISHED", level_change: "UP" }),
      observation({ metric_key: "TYPE_STRUCTURE", previous_state: "INSUFFICIENT_EVIDENCE", current_state: "ASSESSED", previous_value: null, current_value: null, delta: null, previous_level: null, current_level: "DEVELOPING", status: "INSUFFICIENT_EVIDENCE" }),
    ],
    skill_gaps: [
      observation({ metric_key: "FUNCTION_DESIGN", better: "LOWER", previous_state: "GAP", current_state: "NO_GAP", previous_value: "0.1700", current_value: "0.0000", delta: "-0.1700" }),
      observation({ metric_key: "CODE_HYGIENE", better: "LOWER", previous_state: "GAP", current_state: "GAP", previous_value: "0.2800", current_value: "0.3400", delta: "0.0600", status: "REGRESSED" }),
    ],
  };
  const snapshot: GrowthSnapshot = {
    id: GROWTH_ID,
    type: "growth_snapshot",
    project_id: project.id,
    status: "COMPARED",
    assessed_at: "2026-10-07T12:00:00Z",
    previous_assessed_at: "2026-10-01T12:00:00Z",
    current: ref("2"),
    previous: ref("1"),
    summary: summarize(body),
    events: [
      { kind: "IMPROVED", metric_type: "DNA", metric_key: "OVERALL", previous_value: "0.3050", current_value: "0.8050", delta: "0.5000" },
      { kind: "IMPROVED", metric_type: "COMPETENCY", metric_key: "FUNCTION_DESIGN", previous_value: "0.4800", current_value: "0.6700", delta: "0.1900" },
      { kind: "LEVEL_UP", previous_level: "DEVELOPING", current_level: "ESTABLISHED", metric_type: "COMPETENCY", metric_key: "FUNCTION_DESIGN", previous_value: "0.4800", current_value: "0.6700", delta: "0.1900" },
      { kind: "GAP_CLOSED", metric_type: "SKILL_GAP", metric_key: "FUNCTION_DESIGN", previous_value: "0.1700", current_value: "0.0000", delta: "-0.1700" },
      { kind: "REGRESSED", metric_type: "SKILL_GAP", metric_key: "CODE_HYGIENE", previous_value: "0.2800", current_value: "0.3400", delta: "0.0600" },
    ],
    rules_version: "1.0.0",
    created_at: "2026-10-07T12:00:05Z",
    notice: NOTICE,
    versions,
    previous_versions: versions,
    differences: [],
    rules: { version: "1.0.0", fingerprint: "b983b8b800846927fbcfed9cd806026c7994ca2a8c86ecd58d22dca021fdf321", current: true },
    ...body,
    activity: { roadmap_steps_completed: 3, challenges_passed: 1 },
  };
  return { ...snapshot, ...overrides };
}

export function notEstablished(overrides: Partial<GrowthSnapshot> = {}): GrowthSnapshot {
  return compared({
    id: OLD_GROWTH_ID,
    status: "NOT_ESTABLISHED",
    assessed_at: "2026-10-01T12:00:00Z",
    previous_assessed_at: null,
    current: ref("1"),
    previous: null,
    previous_versions: null,
    summary: { observations: 0, statuses: emptyStatuses(), level_changes: { UP: 0, DOWN: 0 } },
    events: [],
    dna: [],
    competencies: [],
    skill_gaps: [],
    activity: null,
    ...overrides,
  });
}

export function incomparable(): GrowthSnapshot {
  return notEstablished({
    id: GROWTH_ID,
    status: "INCOMPARABLE",
    assessed_at: "2026-10-07T12:00:00Z",
    previous_assessed_at: "2026-10-01T12:00:00Z",
    current: ref("2"),
    previous: ref("1"),
    previous_versions: { ...versions, competency_version: "0.9.0" },
    differences: ["competency_version"],
    activity: { roadmap_steps_completed: 0, challenges_passed: 0 },
  });
}

/** Only unchanged observations. */
export function unchanged(): GrowthSnapshot {
  const body = { dna: [observation({ previous_value: "0.8050", current_value: "0.8100", delta: "0.0050", status: "UNCHANGED" })], competencies: [], skill_gaps: [] };
  return compared({ ...body, summary: summarize(body), events: [] });
}

/** Every observation unmeasured. */
export function insufficient(): GrowthSnapshot {
  const body = {
    dna: [observation({ previous_state: "READY", current_state: "INSUFFICIENT_DATA", previous_value: null, current_value: null, delta: null, status: "INSUFFICIENT_EVIDENCE" })],
    competencies: [],
    skill_gaps: [],
  };
  return compared({ ...body, summary: summarize(body), events: [] });
}

export function summaryOf(snapshot: GrowthSnapshot): GrowthSnapshotSummary {
  const { id, type, project_id, status, assessed_at, previous_assessed_at, current, previous, summary, events, rules_version, created_at } = snapshot;
  return { id, type, project_id, status, assessed_at, previous_assessed_at, current, previous, summary, events, rules_version, created_at };
}

export function overview(latest: GrowthSnapshot | null, overrides: Partial<GrowthOverview> = {}): GrowthOverview {
  return { state: latest === null ? "NO_ASSESSMENT" : latest.status, notice: NOTICE, latest, series: [], ...overrides };
}
