import { describe, expect, it } from "vitest";

import { formatBytes, formatDate, formatDateTime, languageLabel, slugify } from "@/lib/projects/format";

describe("project formatting", () => {
  it("builds slugs that satisfy the backend pattern", () => {
    expect(slugify("Billing Service")).toBe("billing-service");
    expect(slugify("  Café — API v2! ")).toBe("cafe-api-v2");
    expect(slugify("---")).toBe("");
    expect(slugify("a".repeat(150))).toHaveLength(100);
  });

  it("formats sizes, dates and languages", () => {
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(2048)).toBe("2.0 KB");
    expect(formatBytes(50 * 1024 * 1024)).toBe("50 MB");
    expect(formatDate("2026-10-07T23:30:00Z")).toBe("Oct 7, 2026");
    expect(formatDateTime("2026-10-07T23:30:00Z")).toBe("Oct 7, 2026, 11:30 PM UTC");
    expect(formatDate(null)).toBe("—");
    expect(languageLabel("typescript")).toBe("TypeScript");
    expect(languageLabel("cobol")).toBe("cobol");
    expect(languageLabel(null)).toBe("—");
  });
});
