import type { AiInsight, AiStatus } from "@/lib/api/types";

import { project } from "@/test/responses";

/** Fixtures shaped exactly like `AiInsightResource` and `AiStatusController` (Phase 29). */

export const INSIGHT_ID = "01k8a1b2c3d4e5f6g7h8j9k0m1";
export const SUBJECT_ID = "01k8a1b2c3d4e5f6g7h8j9k0m2";

export const aiReady: AiStatus = {
  enabled: true,
  available: true,
  provider: "ollama",
  endpoint: "local",
  model: "local-model",
  reachable: true,
  model_available: true,
};

export const aiDisabled: AiStatus = { enabled: false, available: false, provider: null, endpoint: null, model: null, reachable: null, model_available: null };

export const aiDown: AiStatus = { ...aiReady, available: false, reachable: false, model_available: null };

const base: AiInsight = {
  id: INSIGHT_ID,
  type: "ai_insight",
  project_id: project.id,
  kind: "GROWTH_INTERPRETATION",
  subject_id: SUBJECT_ID,
  status: "QUEUED",
  notice: "AI-generated interpretation of deterministic evidence.",
  versions: { insight: "1.0.0", input_schema: "insight-input/v1", output_schema: "insight/v1", prompt: "insight-prompt/v1" },
  fingerprints: { specification: "a".repeat(64), prompt: "b".repeat(64), input: "c".repeat(64), output: null },
  provider: { name: "ollama", model: "local-model", served_model: null },
  usage: { input_tokens: null, output_tokens: null, duration_ms: null },
  attempts: 0,
  output: null,
  evidence: [
    { id: "growth:summary", kind: "growth", label: "Growth summary", description: "Compared with the previous analysis.", facts: { improved: 2, regressed: 0 } },
    { id: "obs:DNA:modularity", kind: "observation", label: "Modularity", description: "A CodeDNA dimension.", facts: { status: "IMPROVED", delta: "4.0", note: null } },
  ],
  failure: null,
  created_at: "2026-10-01T10:00:00Z",
  started_at: null,
  completed_at: null,
};

export const queuedInsight: AiInsight = base;
export const runningInsight: AiInsight = { ...base, status: "RUNNING", attempts: 1, started_at: "2026-10-01T10:00:05Z" };
export const succeededInsight: AiInsight = {
  ...base,
  status: "SUCCEEDED",
  attempts: 1,
  started_at: "2026-10-01T10:00:05Z",
  completed_at: "2026-10-01T10:00:35Z",
  fingerprints: { ...base.fingerprints, output: "d".repeat(64) },
  usage: { input_tokens: 812, output_tokens: 190, duration_ms: 30400 },
  output: {
    schema_version: "insight/v1",
    summary: { text: "Modularity improved since the previous analysis.", evidence_refs: ["growth:summary", "obs:DNA:modularity"] },
    points: [{ title: "Smaller modules", description: "<b>Modules</b> are split more clearly.", evidence_refs: ["obs:DNA:modularity"] }],
    next_steps: [],
    limitations: [{ description: "Only two analyses were compared.", evidence_refs: ["growth:summary"] }],
  },
};
export const failedInsight: AiInsight = {
  ...base,
  status: "FAILED",
  attempts: 3,
  failure: { code: "PROVIDER_UNAVAILABLE", message: "The local AI service could not be reached." },
};
