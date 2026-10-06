import type { AnalysisRun, DnaComponent, DnaDimension, DnaSnapshot, DnaSnapshotSummary } from "@/lib/api/types";

import { project } from "./responses";

/**
 * DNA responses shaped exactly like the Laravel API's (DnaSnapshotResource,
 * DnaSnapshotSummaryResource). Values are those the scoring engine 1.0.0
 * produces for 40 functions, 10 types and 10 files (backend
 * tests/Feature/Dna/DnaApiTest.php).
 */

export const DNA_ID = "01k6p0a1b2c3d4e5f6g7h8j9dn";
export const RUN_ID = "01k6p0a1b2c3d4e5f6g7h8j9rn";
export const SOURCE_ID = "01k6p0a1b2c3d4e5f6g7h8j901";

function component(overrides: Partial<DnaComponent> & Pick<DnaComponent, "key">): DnaComponent {
  return {
    description: null,
    share: true,
    status: "AVAILABLE",
    required: true,
    weight: "0.2500",
    value: "0.0000",
    score: "1.0000",
    best: "0.0000",
    worst: "0.2000",
    minimum_denominator: 5,
    numerator: [],
    denominator: [{ metric: "metrics.overall.functions_total", value: 40 }],
    ...overrides,
  };
}

const complexity: DnaDimension = {
  dimension: "COMPLEXITY",
  name: "Complexity",
  description: "How much branching the functions contain, from cyclomatic complexity and nesting.",
  status: "SCORED",
  unavailable_reason: null,
  score: "0.8125",
  weight: "0.4000",
  effective_weight: "0.4000",
  contribution: "0.3250",
  data_quality: "0.9000",
  components: [
    component({
      key: "mean_cyclomatic_complexity",
      description: "complexity_total / functions_total: average cyclomatic complexity per function (1 is the minimum).",
      share: false,
      weight: "0.5000",
      value: "4.0000",
      score: "0.7500",
      best: "2.0000",
      worst: "10.0000",
      numerator: [{ metric: "metrics.overall.complexity_total", value: 160 }],
    }),
    component({
      key: "complex_function_share",
      value: "0.0500",
      score: "0.7500",
      numerator: [{ metric: "metrics.overall.complexity_over_threshold", value: 2 }],
    }),
    component({ key: "deep_nesting_share", numerator: [{ metric: "findings.by_rule.structure/nesting-depth", value: 0 }] }),
  ],
};

const structure: DnaDimension = {
  dimension: "STRUCTURE",
  name: "Structure",
  description: "How large functions, parameter lists and types are.",
  status: "SCORED",
  unavailable_reason: null,
  score: "0.9000",
  weight: "0.4000",
  effective_weight: "0.4000",
  contribution: "0.3600",
  data_quality: "0.9000",
  components: [
    component({
      key: "long_function_share",
      weight: "0.4000",
      value: "0.0250",
      score: "0.7500",
      worst: "0.1000",
      numerator: [{ metric: "findings.by_rule.structure/function-length", value: 1 }],
    }),
    component({ key: "long_parameter_list_share", weight: "0.3000", numerator: [{ metric: "findings.by_rule.structure/parameter-count", value: 0 }] }),
    component({
      key: "large_type_share",
      weight: "0.3000",
      required: false,
      minimum_denominator: 3,
      numerator: [{ metric: "findings.by_rule.structure/class-length", value: 0 }],
      denominator: [{ metric: "metrics.overall.types", value: 10 }],
    }),
  ],
};

const hygiene: DnaDimension = {
  dimension: "CODE_HYGIENE",
  name: "Code hygiene",
  description: "Whether the analyzable files parse without syntax errors.",
  status: "SCORED",
  unavailable_reason: null,
  score: "0.6000",
  weight: "0.2000",
  effective_weight: "0.2000",
  contribution: "0.1200",
  data_quality: "0.9000",
  components: [
    component({
      key: "syntax_error_share",
      weight: "1.0000",
      value: "0.1000",
      score: "0.6000",
      worst: "0.2500",
      minimum_denominator: 3,
      numerator: [{ metric: "metrics.overall.files_parse_error", value: 1 }],
      denominator: [
        { metric: "metrics.overall.files_parsed", value: 9 },
        { metric: "metrics.overall.files_parse_error", value: 1 },
      ],
    }),
  ],
};

export const readySnapshot: DnaSnapshot = {
  id: DNA_ID,
  type: "dna_snapshot",
  project_id: project.id,
  analysis_run_id: RUN_ID,
  source_snapshot_id: SOURCE_ID,
  status: "READY",
  overall_score: "0.8050",
  data_quality: "0.9000",
  scoring_version: "1.0.0",
  specification_fingerprint: "c07bd65575b0423973e072eaa55b6d28bb6a46e52290945646810983ba02763b",
  versions: { scoring: "1.0.0", metrics: "1.0", analyzer: "0.2.0", ir: "1.1", contract: "1.0" },
  result_hash: "b".repeat(64),
  created_at: "2026-10-12T08:00:00Z",
  source_snapshot: { id: SOURCE_ID, version: 1, file_count: 12, primary_language: "php", created_at: "2026-10-12T07:55:00Z" },
  analysis_run: { id: RUN_ID, result_type: "static_analysis", status: "SUCCEEDED", completed_at: "2026-10-12T07:59:00Z" },
  dimensions: [complexity, structure, hygiene],
  aggregation: {
    method: "weighted_mean_of_scored_dimensions",
    minimum_scored_dimensions: 2,
    scored_dimensions: ["COMPLEXITY", "STRUCTURE", "CODE_HYGIENE"],
    unavailable_dimensions: [],
    scored_weight: "1.0000",
    renormalized: false,
  },
  availability: { AVAILABLE: 7, INSUFFICIENT_EVIDENCE: 0, UNSUPPORTED: 0, MISSING: 0 },
  data_quality_breakdown: {
    parse_coverage: { value: "0.9000", weight: "0.5000", files_parsed: 9, files_analyzable: 10 },
    evidence_volume: { value: "0.8000", weight: "0.2500", functions: 40, target: 50 },
    metric_availability: { value: "1.0000", weight: "0.2500", available_components: 7, components: 7 },
  },
};

/** The captured analyzer fixture: 3 functions, so only CODE_HYGIENE can be scored. */
export const insufficientSnapshot: DnaSnapshot = {
  ...readySnapshot,
  status: "INSUFFICIENT_DATA",
  overall_score: null,
  data_quality: "0.3841",
  dimensions: [
    {
      ...complexity,
      status: "UNAVAILABLE",
      unavailable_reason: "INSUFFICIENT_EVIDENCE",
      score: null,
      effective_weight: null,
      contribution: null,
      data_quality: "0.3841",
      components: complexity.components.map((c) => ({
        ...c,
        status: "INSUFFICIENT_EVIDENCE",
        value: null,
        score: null,
        denominator: [{ metric: "metrics.overall.functions_total", value: 3 }],
      })),
    },
    {
      ...structure,
      status: "UNAVAILABLE",
      unavailable_reason: "INSUFFICIENT_EVIDENCE",
      score: null,
      effective_weight: null,
      contribution: null,
      components: structure.components.map((c) => ({ ...c, status: "INSUFFICIENT_EVIDENCE", value: null, score: null })),
    },
    { ...hygiene, score: "0.0000", effective_weight: null, contribution: null },
  ],
  aggregation: {
    method: "weighted_mean_of_scored_dimensions",
    minimum_scored_dimensions: 2,
    scored_dimensions: ["CODE_HYGIENE"],
    unavailable_dimensions: ["COMPLEXITY", "STRUCTURE"],
    scored_weight: "0.2000",
    renormalized: false,
  },
  availability: { AVAILABLE: 1, INSUFFICIENT_EVIDENCE: 6, UNSUPPORTED: 0, MISSING: 0 },
};

export function summary(snapshot: DnaSnapshot, overrides: Partial<DnaSnapshotSummary> = {}): DnaSnapshotSummary {
  return {
    id: snapshot.id,
    type: "dna_snapshot",
    project_id: snapshot.project_id,
    analysis_run_id: snapshot.analysis_run_id,
    source_snapshot_id: snapshot.source_snapshot_id,
    source_snapshot_version: snapshot.source_snapshot?.version ?? null,
    status: snapshot.status,
    overall_score: snapshot.overall_score,
    data_quality: snapshot.data_quality,
    scoring_version: snapshot.scoring_version,
    metrics_version: snapshot.versions.metrics,
    created_at: snapshot.created_at,
    ...overrides,
  };
}

export function analysisRun(overrides: Partial<AnalysisRun> = {}): AnalysisRun {
  return {
    id: RUN_ID,
    type: "analysis_run",
    project_id: project.id,
    source_snapshot_id: SOURCE_ID,
    result_type: "static_analysis",
    status: "RUNNING",
    created_at: "2026-10-12T07:58:00Z",
    started_at: "2026-10-12T07:58:01Z",
    completed_at: null,
    failure: null,
    result: null,
    ...overrides,
  };
}
