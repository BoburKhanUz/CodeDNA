import { describe, expect, it } from "vitest";

import {
  barWidth,
  componentLabel,
  evidenceStatusLabel,
  formatCount,
  formatDecimal,
  formatPercent,
  formatScore,
  formatWeight,
  metricLabel,
} from "@/lib/dna/format";

describe("CodeDNA formatting", () => {
  it("shows 0–1 decimal strings on a 0–100 scale without rounding", () => {
    expect(formatScore("0.8123")).toBe("81.23");
    expect(formatScore("0.8050")).toBe("80.50");
    expect(formatScore("0.0013")).toBe("0.13");
    expect(formatScore("0.0000")).toBe("0.00");
    expect(formatScore("1.0000")).toBe("100.00");
    // Values a float conversion would distort keep their digits.
    expect(formatScore("0.5555")).toBe("55.55");
    expect(formatScore("0.2857")).toBe("28.57");
  });

  it("never turns a missing value into 0", () => {
    for (const value of [null, "", "0.8", "0.81234", "-0.5000", "abc"]) {
      expect(formatScore(value)).toBeNull();
      expect(formatPercent(value)).toBeNull();
      expect(formatWeight(value)).toBeNull();
      expect(formatDecimal(value)).toBeNull();
    }
    expect(formatCount(null)).toBe("—");
    expect(formatCount(0)).toBe("0");
    expect(barWidth(null)).toBe("0%");
  });

  it("formats data quality, weights, ratios and counts", () => {
    expect(formatPercent("0.9134")).toBe("91.34%");
    expect(formatWeight("0.4000")).toBe("40%");
    expect(formatWeight("0.6667")).toBe("66.67%");
    expect(formatWeight("1.0000")).toBe("100%");
    expect(formatWeight("0.0500")).toBe("5%");
    expect(formatDecimal("4.0000")).toBe("4.00");
    expect(formatDecimal("3.3333")).toBe("3.3333");
    expect(formatDecimal("10.0000")).toBe("10.00");
    expect(formatDecimal("2.0100")).toBe("2.01");
    expect(formatCount(12345)).toBe("12,345");
    expect(barWidth("0.8050")).toBe("80.50%");
  });

  it("labels components, metrics and evidence statuses", () => {
    expect(componentLabel("mean_cyclomatic_complexity")).toBe("Average cyclomatic complexity");
    expect(componentLabel("future_metric_share")).toBe("Future metric share");
    expect(metricLabel("metrics.overall.functions_total")).toBe("Functions");
    expect(metricLabel("findings.by_rule.structure/nesting-depth")).toBe("Functions above the nesting threshold");
    expect(metricLabel("metrics.overall.lines_code")).toBe("Lines code");
    expect(evidenceStatusLabel("INSUFFICIENT_EVIDENCE")).toBe("Insufficient evidence");
    expect(evidenceStatusLabel("UNSUPPORTED")).toBe("Not supported for the analyzed languages");
    expect(evidenceStatusLabel("MISSING")).toBe("Not available in the analysis result");
    expect(evidenceStatusLabel(null)).toBe("Unknown");
  });
});
