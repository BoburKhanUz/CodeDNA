import type { Competency, CompetencyEvidence, CompetencySnapshot, CompetencySnapshotSummary } from "@/lib/api/types";

import { DNA_ID, RUN_ID, SOURCE_ID } from "./dna";
import { project } from "./responses";

/**
 * Competency responses shaped exactly like the Laravel API's
 * (CompetencySnapshotResource). Values are those competency engine 1.0.0
 * produces from the DNA of 40 functions, 10 types and 10 files (backend
 * tests/Feature/Competency/CompetencyApiTest.php).
 */

export const COMPETENCY_ID = "01k6p0a1b2c3d4e5f6g7h8j9cm";

function evidence(overrides: Partial<CompetencyEvidence> & Pick<CompetencyEvidence, "source">): CompetencyEvidence {
  const [dimension, component] = overrides.source.split(".");
  return {
    dimension,
    component,
    rationale: null,
    share: true,
    weight: "1.0000",
    required: true,
    status: "AVAILABLE",
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

const terms = { parse_coverage: "0.9000", evidence_volume: "0.8000", evidence_availability: "1.0000" };

const complexity: Competency = {
  key: "COMPLEXITY_MANAGEMENT",
  name: "Complexity management",
  description: "Keeping decision logic per function small: how much branching (cyclomatic complexity) functions contain.",
  status: "ASSESSED",
  score: "0.7500",
  level: "ESTABLISHED",
  evidence_quality: "0.9000",
  evidence_quality_terms: terms,
  limitations: [],
  evidence: [
    evidence({
      source: "COMPLEXITY.mean_cyclomatic_complexity",
      rationale: "Average branching per function: the central measure of decision logic.",
      share: false,
      weight: "0.6000",
      value: "4.0000",
      score: "0.7500",
      best: "2.0000",
      worst: "10.0000",
      numerator: [{ metric: "metrics.overall.complexity_total", value: 160 }],
    }),
    evidence({
      source: "COMPLEXITY.complex_function_share",
      weight: "0.4000",
      value: "0.0500",
      score: "0.7500",
      numerator: [{ metric: "metrics.overall.complexity_over_threshold", value: 2 }],
    }),
  ],
};

const functionDesign: Competency = {
  key: "FUNCTION_DESIGN",
  name: "Function design",
  description: "Shaping functions so they stay short, take few parameters and keep control flow shallow.",
  status: "ASSESSED",
  score: "0.9000",
  level: "STRONG",
  evidence_quality: "0.9000",
  evidence_quality_terms: terms,
  limitations: [],
  evidence: [
    evidence({ source: "STRUCTURE.long_function_share", weight: "0.4000", value: "0.0250", score: "0.7500", worst: "0.1000", numerator: [{ metric: "findings.by_rule.structure/function-length", value: 1 }] }),
    evidence({ source: "STRUCTURE.long_parameter_list_share", weight: "0.3000", numerator: [{ metric: "findings.by_rule.structure/parameter-count", value: 0 }] }),
    evidence({ source: "COMPLEXITY.deep_nesting_share", weight: "0.3000", numerator: [{ metric: "findings.by_rule.structure/nesting-depth", value: 0 }] }),
  ],
};

const typeStructure: Competency = {
  key: "TYPE_STRUCTURE",
  name: "Type structure",
  description: "Keeping types (classes, interfaces, structs, traits, enums) within a manageable size.",
  status: "ASSESSED",
  score: "1.0000",
  level: "STRONG",
  evidence_quality: "0.9000",
  evidence_quality_terms: terms,
  limitations: [],
  evidence: [
    evidence({
      source: "STRUCTURE.large_type_share",
      minimum_denominator: 3,
      numerator: [{ metric: "findings.by_rule.structure/class-length", value: 0 }],
      denominator: [{ metric: "metrics.overall.types", value: 10 }],
    }),
  ],
};

const hygiene: Competency = {
  key: "CODE_HYGIENE",
  name: "Code hygiene",
  description: "Keeping analyzable source files syntactically valid.",
  status: "ASSESSED",
  score: "0.6000",
  level: "DEVELOPING",
  evidence_quality: "0.9000",
  evidence_quality_terms: terms,
  limitations: [],
  evidence: [
    evidence({
      source: "CODE_HYGIENE.syntax_error_share",
      value: "0.1000",
      score: "0.6000",
      worst: "0.2500",
      minimum_denominator: 3,
      numerator: [{ metric: "metrics.overall.files_parse_error", value: 1 }],
      denominator: [
        { metric: "metrics.overall.files_parse_error", value: 1 },
        { metric: "metrics.overall.files_parsed", value: 9 },
      ],
    }),
  ],
};

export const assessedMatrix: CompetencySnapshot = {
  id: COMPETENCY_ID,
  type: "competency_snapshot",
  project_id: project.id,
  dna_snapshot_id: DNA_ID,
  analysis_run_id: RUN_ID,
  source_snapshot_id: SOURCE_ID,
  status: "ASSESSED",
  competency_version: "1.0.0",
  specification_fingerprint: "f9019e29ed358920eb9e12856aecc9f07450c6466ce174b2dc191c7a64f29eac",
  dna_scoring_version: "1.0.0",
  created_at: "2026-10-12T08:00:01Z",
  levels: [
    { level: "NOT_ESTABLISHED", name: "Not established", ordinal: 0, minimum_score: "0.0000" },
    { level: "DEVELOPING", name: "Developing", ordinal: 1, minimum_score: "0.4000" },
    { level: "ESTABLISHED", name: "Established", ordinal: 2, minimum_score: "0.6500" },
    { level: "STRONG", name: "Strong", ordinal: 3, minimum_score: "0.8500" },
  ],
  summary: {
    competencies: 4,
    statuses: { ASSESSED: 4, INSUFFICIENT_EVIDENCE: 0, UNSUPPORTED: 0, MISSING: 0 },
    levels: { NOT_ESTABLISHED: 0, DEVELOPING: 1, ESTABLISHED: 1, STRONG: 2 },
  },
  languages: ["php", "python"],
  dna_snapshot: {
    id: DNA_ID,
    status: "READY",
    overall_score: "0.8050",
    data_quality: "0.9000",
    scoring_version: "1.0.0",
    specification_fingerprint: "c07bd65575b0423973e072eaa55b6d28bb6a46e52290945646810983ba02763b",
    created_at: "2026-10-12T08:00:00Z",
  },
  source_snapshot: { id: SOURCE_ID, version: 1, file_count: 12, primary_language: "php", created_at: "2026-10-12T07:55:00Z" },
  analysis_run: { id: RUN_ID, result_type: "static_analysis", status: "SUCCEEDED", completed_at: "2026-10-12T07:59:00Z" },
  competencies: [complexity, functionDesign, typeStructure, hygiene],
};

const notAssessed = (competency: Competency, status: Competency["status"], quality: string): Competency => ({
  ...competency,
  status,
  score: null,
  level: null,
  evidence_quality: quality,
  evidence: competency.evidence.map((item) => ({ ...item, status: status === "ASSESSED" ? "AVAILABLE" : status, value: null, score: null })),
});

/** Nothing could be assessed: too few functions and types, and unsupported hygiene evidence. */
export const insufficientMatrix: CompetencySnapshot = {
  ...assessedMatrix,
  status: "INSUFFICIENT_DATA",
  summary: {
    competencies: 4,
    statuses: { ASSESSED: 0, INSUFFICIENT_EVIDENCE: 3, UNSUPPORTED: 1, MISSING: 0 },
    levels: { NOT_ESTABLISHED: 0, DEVELOPING: 0, ESTABLISHED: 0, STRONG: 0 },
  },
  competencies: [
    notAssessed(complexity, "INSUFFICIENT_EVIDENCE", "0.3484"),
    notAssessed(functionDesign, "INSUFFICIENT_EVIDENCE", "0.3484"),
    notAssessed(typeStructure, "INSUFFICIENT_EVIDENCE", "0.3484"),
    {
      ...notAssessed(hygiene, "UNSUPPORTED", "0.3484"),
      evidence: [{ ...hygiene.evidence[0], status: "UNSUPPORTED", value: null, score: null, numerator: [{ metric: "metrics.overall.files_parse_error", value: null }] }],
    },
  ],
};

export function competencySummary(snapshot: CompetencySnapshot): CompetencySnapshotSummary {
  return {
    id: snapshot.id,
    type: "competency_snapshot",
    project_id: snapshot.project_id,
    dna_snapshot_id: snapshot.dna_snapshot_id,
    analysis_run_id: snapshot.analysis_run_id,
    source_snapshot_id: snapshot.source_snapshot_id,
    status: snapshot.status,
    competency_version: snapshot.competency_version,
    dna_scoring_version: snapshot.dna_scoring_version,
    summary: snapshot.summary,
    created_at: snapshot.created_at,
  };
}
