import type { GrowthSummary, HistoryComparison, HistoryPoint } from "@/lib/api/types";
import { observation } from "@/test/growth";
import { project } from "@/test/responses";

/** Historical DNA responses shaped exactly like HistoryController's and HistoryPointResource's. */

export const NOTICE =
  "Historical DNA shows each code assessment exactly as it was recorded. Stored values are never recalculated or rewritten, and assessments measured with different versions are never compared. Learning activity is context only.";

export const COMMIT = "0123456789abcdef0123456789abcdef01234567";

const V1 = { dna: "seg-dna-v1", competency: "seg-comp-v1", skill_gaps: "seg-gap-v1" };

function zeroSummary(): GrowthSummary {
  const zero = { IMPROVED: 0, REGRESSED: 0, UNCHANGED: 0, INSUFFICIENT_EVIDENCE: 0 };
  return { observations: 0, statuses: { DNA: { ...zero }, COMPETENCY: { ...zero }, SKILL_GAP: { ...zero } }, level_changes: { UP: 0, DOWN: 0 } };
}

export const id = (n: number) => `01k6t0a1b2c3d4e5f6g7h8j9${String(n).padStart(2, "0")}`;

interface PointOptions {
  n: number;
  analyzedAt: string;
  scores?: [string | null, string | null, string | null];
  overall?: string | null;
  scoringVersion?: string;
  segment?: string;
  functionDesign?: { score: string; level: string; gap: string; status: "GAP" | "NO_GAP"; priority: "LOW" | "MEDIUM" | "HIGH" | null };
  noCompetency?: boolean;
  github?: boolean;
  growth?: HistoryPoint["growth"];
}

export function point(o: PointOptions): HistoryPoint {
  const [complexity, structure, hygiene] = o.scores ?? ["0.6100", "0.5500", "0.8000"];
  const fd = o.functionDesign ?? { score: "0.5300", level: "DEVELOPING", gap: "0.2200", status: "GAP", priority: "HIGH" };
  const dimension = (dimension: string, name: string, score: string | null) => ({
    dimension,
    name,
    status: score === null ? "UNAVAILABLE" : "SCORED",
    score,
    data_quality: score === null ? null : "0.9000",
  });
  return {
    id: id(o.n),
    type: "history_point",
    project_id: project.id,
    analyzed_at: o.analyzedAt,
    analysis_run_id: `01k6t0a1b2c3d4e5f6g7h8jr${String(o.n).padStart(2, "0")}`,
    layers: { dna: "AVAILABLE", competency: o.noCompetency ? "UNAVAILABLE" : "AVAILABLE", skill_gaps: o.noCompetency ? "UNAVAILABLE" : "AVAILABLE" },
    versions: { dna_scoring_version: o.scoringVersion ?? "1.0.0", metrics_version: "1.0" },
    segments: o.noCompetency
      ? { dna: o.segment ?? V1.dna, competency: null, skill_gaps: null }
      : { dna: o.segment ?? V1.dna, competency: `${o.segment ?? V1.dna}-c`, skill_gaps: `${o.segment ?? V1.dna}-g` },
    dna: {
      snapshot_id: id(o.n),
      status: o.overall === null ? "INSUFFICIENT_DATA" : "READY",
      overall_score: o.overall === undefined ? "0.6500" : o.overall,
      data_quality: "0.9000",
      scoring_version: o.scoringVersion ?? "1.0.0",
      specification_fingerprint: "a".repeat(64),
      metrics_version: "1.0",
      created_at: o.analyzedAt,
      dimensions: [dimension("COMPLEXITY", "Complexity", complexity), dimension("STRUCTURE", "Structure", structure), dimension("CODE_HYGIENE", "Code hygiene", hygiene)],
    },
    competency: o.noCompetency
      ? null
      : {
          snapshot_id: `01k6t0a1b2c3d4e5f6g7h8jc${String(o.n).padStart(2, "0")}`,
          status: "ASSESSED",
          competency_version: "1.0.0",
          specification_fingerprint: "b".repeat(64),
          created_at: o.analyzedAt,
          competencies: [
            { key: "FUNCTION_DESIGN", name: "Function design", status: "ASSESSED", score: fd.score, level: fd.level, evidence_quality: "0.9000" },
            { key: "TYPE_STRUCTURE", name: "Type structure", status: "INSUFFICIENT_EVIDENCE", score: null, level: null, evidence_quality: null },
          ],
        },
    skill_gaps: o.noCompetency
      ? null
      : {
          snapshot_id: `01k6t0a1b2c3d4e5f6g7h8jg${String(o.n).padStart(2, "0")}`,
          status: fd.status === "GAP" ? "GAPS_IDENTIFIED" : "NO_MATERIAL_GAPS",
          skill_gap_version: "1.0.0",
          target_profile: "ENGINEERING_STANDARD",
          target_profile_version: "1.0.0",
          specification_fingerprint: "c".repeat(64),
          created_at: o.analyzedAt,
          results: [
            {
              competency_key: "FUNCTION_DESIGN",
              name: "Function design",
              status: fd.status,
              current_score: fd.score,
              target_score: "0.7500",
              gap: fd.gap,
              material_gap: fd.status === "GAP",
              priority: fd.priority,
              evidence_quality: "0.9000",
              current_level: fd.level,
            },
            {
              competency_key: "TYPE_STRUCTURE",
              name: "Type structure",
              status: "INSUFFICIENT_EVIDENCE",
              current_score: null,
              target_score: "0.7500",
              gap: null,
              material_gap: null,
              priority: null,
              evidence_quality: null,
              current_level: null,
            },
          ],
        },
    source: {
      snapshot_id: `01k6t0a1b2c3d4e5f6g7h8js${String(o.n).padStart(2, "0")}`,
      version: o.n,
      origin: o.github ? "GITHUB" : "UPLOAD",
      source_hash: "d".repeat(64),
      file_count: 42,
      primary_language: "php",
      created_at: o.analyzedAt,
      github: o.github ? { repository: "octo-org/example-repo", ref: "main", commit_sha: COMMIT } : null,
    },
    growth: o.growth === undefined ? { id: `01k6t0a1b2c3d4e5f6g7h8jw${String(o.n).padStart(2, "0")}`, status: "NOT_ESTABLISHED", previous_dna_snapshot_id: null, previous_assessed_at: null, rules_version: "1.0.0", summary: zeroSummary(), events: [] } : o.growth,
  };
}

export function comparedGrowth(n: number, previous: number, events: NonNullable<HistoryPoint["growth"]>["events"]): HistoryPoint["growth"] {
  return {
    id: `01k6t0a1b2c3d4e5f6g7h8jw${String(n).padStart(2, "0")}`,
    status: "COMPARED",
    previous_dna_snapshot_id: id(previous),
    previous_assessed_at: "2026-10-01T10:00:00Z",
    rules_version: "1.0.0",
    summary: zeroSummary(),
    events,
  };
}

/** Three compatible assessments, newest first: Function design GAP (High) → GAP (Medium) → NO_GAP. */
export function threeCompatible(): HistoryPoint[] {
  return [
    point({
      n: 3,
      analyzedAt: "2026-10-03T10:00:00Z",
      scores: ["0.7900", "0.7100", "0.9600"],
      functionDesign: { score: "0.8000", level: "ESTABLISHED", gap: "0.0000", status: "NO_GAP", priority: null },
      github: true,
      growth: comparedGrowth(3, 2, [
        { kind: "GAP_CLOSED", metric_type: "SKILL_GAP", metric_key: "FUNCTION_DESIGN", previous_value: "0.1100", current_value: "0.0000", delta: "-0.1100" },
        { kind: "LEVEL_UP", metric_type: "COMPETENCY", metric_key: "FUNCTION_DESIGN", previous_value: "0.6400", current_value: "0.8000", delta: "0.1600", previous_level: "DEVELOPING", current_level: "ESTABLISHED" },
      ]),
    }),
    point({
      n: 2,
      analyzedAt: "2026-10-02T10:00:00Z",
      scores: ["0.7400", "0.6800", "0.9400"],
      functionDesign: { score: "0.6400", level: "DEVELOPING", gap: "0.1100", status: "GAP", priority: "MEDIUM" },
      growth: comparedGrowth(2, 1, [{ kind: "IMPROVED", metric_type: "DNA", metric_key: "COMPLEXITY", previous_value: "0.6100", current_value: "0.7400", delta: "0.1300" }]),
    }),
    point({ n: 1, analyzedAt: "2026-10-01T10:00:00Z" }),
  ];
}

export function comparison(overrides: Partial<HistoryComparison> = {}): HistoryComparison {
  const [to, , from] = threeCompatible();
  return {
    type: "history_comparison",
    notice: NOTICE,
    status: "COMPARED",
    basis: "GROWTH_RULES",
    growth_snapshot_id: null,
    rules: { version: "1.0.0", fingerprint: "e".repeat(64) },
    from,
    to,
    layers: { dna: "COMPARED", competency: "COMPARED", skill_gaps: "COMPARED" },
    differences: [],
    summary: zeroSummary(),
    events: [],
    dna: [
      observation({ metric_key: "OVERALL", previous_value: "0.6500", current_value: "0.6500", delta: "0.0000", status: "UNCHANGED" }),
      observation({ metric_key: "COMPLEXITY", previous_state: "SCORED", current_state: "SCORED", previous_value: "0.6100", current_value: "0.7900", delta: "0.1800" }),
    ],
    competencies: [
      observation({ metric_key: "FUNCTION_DESIGN", previous_state: "ASSESSED", current_state: "ASSESSED", previous_value: "0.5300", current_value: "0.8000", delta: "0.2700", previous_level: "DEVELOPING", current_level: "ESTABLISHED", level_change: "UP" }),
    ],
    skill_gaps: [
      observation({ metric_key: "FUNCTION_DESIGN", better: "LOWER", previous_state: "GAP", current_state: "NO_GAP", previous_value: "0.2200", current_value: "0.0000", delta: "-0.2200" }),
    ],
    activity: { roadmap_steps_completed: 4, challenges_passed: 1 },
    ...overrides,
  };
}
