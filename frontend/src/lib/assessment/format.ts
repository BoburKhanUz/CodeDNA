/** Display helpers for the evidence catalog of an AI assessment. Values are shown as stored; nothing is computed. */

const FACT_LABELS: Record<string, string> = {
  status: "Status",
  score: "Score (0–1)",
  level: "Level",
  value: "Measured value",
  evidence_quality: "Evidence quality (0–1)",
  current_score: "Current score (0–1)",
  target_score: "Target score (0–1)",
  raw_gap: "Gap (0–1)",
  material_gap: "Material gap",
  priority: "Priority",
  priority_capped: "Priority capped",
  competency: "Competency",
  evidence: "Evidence",
  limited_languages: "Partially supported languages",
  unavailable_reason: "Unavailable reason",
  data_quality: "Data quality (0–1)",
  parse_coverage: "Parse coverage (0–1)",
  evidence_volume: "Evidence volume (0–1)",
  metric_availability: "Metric availability (0–1)",
  files_parsed: "Parsed files",
  files_analyzable: "Analyzable files",
  functions: "Functions",
  components_available: "Available components",
  components_total: "Components",
  version: "Version",
  material_gap_threshold: "Material-gap threshold (0–1)",
  partial_support: "Partial support",
  note: "Note",
};

export function factLabel(key: string): string {
  return FACT_LABELS[key] ?? key.replaceAll("_", " ");
}

export function formatFact(value: string | number | boolean | null | string[]): string {
  if (value === null) return "—";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (Array.isArray(value)) return value.length === 0 ? "—" : value.join(", ");
  return String(value);
}
