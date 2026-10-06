import type { DecimalString, DnaEvidenceStatus } from "@/lib/api/types";

/**
 * Display helpers for CodeDNA values. They only reformat the backend's
 * decimal strings ("0.8050" -> "80.50"); no score, weight, share or
 * contribution is ever computed in the browser. The conversion works on the
 * digits, so nothing is rounded or lost to floating point.
 */

const DECIMAL = /^(\d+)\.(\d{4})$/;

/** "0.8050" -> "80.50" (0–1 scale shown as 0–100, exactly). null for null or unexpected input. */
export function formatScore(value: DecimalString | null): string | null {
  const match = value === null ? null : DECIMAL.exec(value);
  if (match === null) return null;
  const whole = `${Number.parseInt(match[1], 10)}${match[2].slice(0, 2)}`.replace(/^0+(?=\d)/, "");
  return `${whole}.${match[2].slice(2)}`;
}

/** "0.9134" -> "91.34%". */
export function formatPercent(value: DecimalString | null): string | null {
  const score = formatScore(value);
  return score === null ? null : `${score}%`;
}

/** Weights read better without trailing zeros: "0.4000" -> "40%", "0.6667" -> "66.67%". */
export function formatWeight(value: DecimalString | null): string | null {
  const score = formatScore(value);
  return score === null ? null : `${score.replace(/\.?0+$/, "")}%`;
}

/** A ratio such as an average: "4.0000" -> "4.00", "3.3333" -> "3.3333" (trailing zeros beyond 2 places dropped). */
export function formatDecimal(value: DecimalString | null): string | null {
  const match = value === null ? null : DECIMAL.exec(value);
  if (match === null) return null;
  return `${Number.parseInt(match[1], 10)}.${match[2].replace(/0{1,2}$/, "").padEnd(2, "0")}`;
}

/** CSS width for a 0–1 decimal string, e.g. "0.8050" -> "80.50%" (0% for null). */
export function barWidth(value: DecimalString | null): string {
  return formatPercent(value) ?? "0%";
}

const COMPONENT_LABELS: Record<string, string> = {
  mean_cyclomatic_complexity: "Average cyclomatic complexity",
  complex_function_share: "Functions above the complexity threshold",
  deep_nesting_share: "Deeply nested functions",
  long_function_share: "Long functions",
  long_parameter_list_share: "Long parameter lists",
  large_type_share: "Large types",
  syntax_error_share: "Files with syntax errors",
};

const METRIC_LABELS: Record<string, string> = {
  "metrics.overall.complexity_total": "Cyclomatic complexity (sum over functions)",
  "metrics.overall.functions_total": "Functions",
  "metrics.overall.complexity_over_threshold": "Functions above the complexity threshold",
  "metrics.overall.types": "Types",
  "metrics.overall.files_parsed": "Parsed files",
  "metrics.overall.files_parse_error": "Files with syntax errors",
  "metrics.overall.files_analyzable": "Analyzable files",
  "findings.by_rule.structure/nesting-depth": "Functions above the nesting threshold",
  "findings.by_rule.structure/function-length": "Functions above the length threshold",
  "findings.by_rule.structure/parameter-count": "Functions above the parameter threshold",
  "findings.by_rule.structure/class-length": "Types above the length threshold",
};

function humanize(key: string): string {
  const words = key.replace(/^.*[./]/, "").replace(/[-_]+/g, " ").trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function componentLabel(key: string): string {
  return COMPONENT_LABELS[key] ?? humanize(key);
}

export function metricLabel(metric: string): string {
  return METRIC_LABELS[metric] ?? humanize(metric);
}

const EVIDENCE_STATUS_LABELS: Record<DnaEvidenceStatus, string> = {
  AVAILABLE: "Measured",
  INSUFFICIENT_EVIDENCE: "Insufficient evidence",
  UNSUPPORTED: "Not supported for the analyzed languages",
  MISSING: "Not available in the analysis result",
};

export function evidenceStatusLabel(status: string | null): string {
  return status !== null && status in EVIDENCE_STATUS_LABELS ? EVIDENCE_STATUS_LABELS[status as DnaEvidenceStatus] : "Unknown";
}

/** A raw count, or a dash when the analysis did not provide it (never 0). */
export function formatCount(value: number | null): string {
  return value === null ? "—" : new Intl.NumberFormat("en").format(value);
}
