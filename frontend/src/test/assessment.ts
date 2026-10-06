import type { AiAssessment, AiAssessmentSummary, AssessmentEvidence } from "@/lib/api/types";

import { COMPETENCY_ID } from "./competency";
import { DNA_ID, RUN_ID, SOURCE_ID } from "./dna";
import { project } from "./responses";
import { SKILL_GAP_ID } from "./skill-gap";

/**
 * AI assessment responses shaped exactly like the Laravel API's
 * (AiAssessmentResource), for the skill gap snapshot of src/test/skill-gap.ts.
 */

export const ASSESSMENT_ID = "01k6p0a1b2c3d4e5f6g7h8j9as";

const evidence: AssessmentEvidence[] = [
  {
    id: "competency:CODE_HYGIENE",
    kind: "competency",
    label: "Code hygiene",
    description: "Keeping analyzable source files syntactically valid.",
    facts: { status: "ASSESSED", level: "DEVELOPING", score: "0.6000", evidence_quality: "0.9000", evidence: ["component:CODE_HYGIENE.syntax_error_share"], limited_languages: [] },
  },
  {
    id: "competency:FUNCTION_DESIGN",
    kind: "competency",
    label: "Function design",
    description: "Shaping functions so they stay short, take few parameters and keep control flow shallow.",
    facts: { status: "ASSESSED", level: "STRONG", score: "0.9000", evidence_quality: "0.9000", evidence: [], limited_languages: [] },
  },
  {
    id: "gap:CODE_HYGIENE",
    kind: "gap",
    label: "Gap: Code hygiene",
    description: "Difference between the competency score and the target of the profile; only a material gap has a priority.",
    facts: { status: "GAP", current_score: "0.6000", target_score: "0.9000", raw_gap: "0.3000", material_gap: true, priority: "HIGH", priority_capped: false, competency: "competency:CODE_HYGIENE" },
  },
  {
    id: "gap:FUNCTION_DESIGN",
    kind: "gap",
    label: "Gap: Function design",
    description: "Difference between the competency score and the target of the profile; only a material gap has a priority.",
    facts: { status: "NO_GAP", current_score: "0.9000", target_score: "0.7500", raw_gap: "0.0000", material_gap: false, priority: null, priority_capped: null, competency: "competency:FUNCTION_DESIGN" },
  },
  {
    id: "quality:data",
    kind: "quality",
    label: "Data quality",
    description: "How much of the analyzed code could be measured: parse coverage, evidence volume and metric availability.",
    facts: { data_quality: "0.9000", files_parsed: 9, files_analyzable: 10 },
  },
];

function assessment(overrides: Partial<AiAssessment> = {}): AiAssessment {
  return {
    id: ASSESSMENT_ID,
    type: "ai_assessment",
    project_id: project.id,
    status: "SUCCEEDED",
    notice: "AI-generated interpretation of the deterministic results. It does not determine or change any score, level, gap, priority or target.",
    lineage: {
      skill_gap_snapshot_id: SKILL_GAP_ID,
      competency_snapshot_id: COMPETENCY_ID,
      dna_snapshot_id: DNA_ID,
      analysis_run_id: RUN_ID,
      source_snapshot_id: SOURCE_ID,
    },
    versions: {
      assessment: "1.0.0",
      input_schema: "assessment-input/1.0.0",
      output_schema: "assessment/v1",
      prompt: "1.0.0",
      dna_scoring: "1.0.0",
      competency: "1.0.0",
      skill_gap: "1.0.0",
      target_profile: "ENGINEERING_STANDARD",
      target_profile_version: "1.0.0",
    },
    fingerprints: { specification: "a".repeat(64), prompt: "b".repeat(64), input: "c".repeat(64), output: "d".repeat(64) },
    provider: { name: "openai_compatible", model: "example-model", served_model: "example-model-2026-01-01" },
    attempts: 1,
    output: {
      schema_version: "assessment/v1",
      summary: { text: "Functions are short and shallow, while syntax validity is below the target of the profile.", evidence_refs: ["gap:FUNCTION_DESIGN", "gap:CODE_HYGIENE"] },
      strengths: [{ title: "Compact functions", description: "Function design meets its target.", evidence_refs: ["competency:FUNCTION_DESIGN", "gap:FUNCTION_DESIGN"] }],
      areas_to_improve: [{ title: "Syntax validity", description: "Some analyzable files contain syntax errors.", evidence_refs: ["gap:CODE_HYGIENE"] }],
      development_insights: [],
      limitations: [{ description: "Testing and security are not measured.", evidence_refs: ["quality:data"] }],
    },
    evidence,
    failure: null,
    created_at: "2026-10-12T08:00:00Z",
    started_at: "2026-10-12T08:00:01Z",
    completed_at: "2026-10-12T08:00:09Z",
    ...overrides,
  };
}

export const succeededAssessment = assessment();
export const queuedAssessment = assessment({ status: "QUEUED", output: null, fingerprints: { ...assessment().fingerprints, output: null }, provider: { name: "openai_compatible", model: "example-model", served_model: null }, attempts: 0, started_at: null, completed_at: null });
export const runningAssessment = assessment({ ...queuedAssessment, status: "RUNNING", attempts: 1 });
export const failedAssessment = assessment({
  ...queuedAssessment,
  status: "FAILED",
  attempts: 1,
  failure: { code: "INVALID_OUTPUT", message: "The AI response did not meet the assessment rules and was discarded." },
  completed_at: "2026-10-12T08:00:09Z",
});

export function assessmentSummary(a: AiAssessment): AiAssessmentSummary {
  return {
    id: a.id,
    type: "ai_assessment",
    project_id: a.project_id,
    skill_gap_snapshot_id: a.lineage.skill_gap_snapshot_id,
    status: a.status,
    assessment_version: a.versions.assessment,
    provider: a.provider.name,
    model: a.provider.model,
    failure: a.failure,
    created_at: a.created_at,
    completed_at: a.completed_at,
  };
}
