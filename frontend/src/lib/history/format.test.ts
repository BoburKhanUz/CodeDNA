import { describe, expect, it } from "vitest";

import { chronological, dimensionScore, gapClosed, growthLabel, lines, markers, segments, shortSha, sourceLabel } from "@/lib/history/format";
import { point, threeCompatible } from "@/test/history";

const score = (dimension: string) => (p: Parameters<typeof dimensionScore>[0]) => dimensionScore(p, dimension);

describe("history format", () => {
  it("orders points oldest first without changing the input", () => {
    const points = threeCompatible();
    expect(chronological(points).map((p) => p.source.version)).toEqual([1, 2, 3]);
    expect(points.map((p) => p.source.version)).toEqual([3, 2, 1]);
  });

  it("groups consecutive points by the server's segment key", () => {
    const points = chronological([
      point({ n: 4, analyzedAt: "2026-10-04T00:00:00Z", segment: "a" }),
      point({ n: 3, analyzedAt: "2026-10-03T00:00:00Z", segment: "b" }),
      point({ n: 2, analyzedAt: "2026-10-02T00:00:00Z", segment: "b" }),
      point({ n: 1, analyzedAt: "2026-10-01T00:00:00Z", segment: "a" }),
    ]);
    expect(segments(points, "dna")).toEqual([
      { key: "a", indexes: [0] },
      { key: "b", indexes: [1, 2] },
      { key: "a", indexes: [3] },
    ]);
  });

  it("ends a segment at a point without the layer", () => {
    const points = chronological([
      point({ n: 3, analyzedAt: "2026-10-03T00:00:00Z" }),
      point({ n: 2, analyzedAt: "2026-10-02T00:00:00Z", noCompetency: true }),
      point({ n: 1, analyzedAt: "2026-10-01T00:00:00Z" }),
    ]);
    expect(segments(points, "competency").map((s) => s.indexes)).toEqual([[0], [2]]);
    expect(segments(points, "dna").map((s) => s.indexes)).toEqual([[0, 1, 2]]);
  });

  it("draws lines only for two or more consecutive measured points in one segment", () => {
    const points = chronological(threeCompatible());
    expect(lines(points, "dna", score("COMPLEXITY"))).toEqual([[{ index: 0, value: 61 }, { index: 1, value: 74 }, { index: 2, value: 79 }]]);
    expect(lines([points[0]], "dna", score("COMPLEXITY"))).toEqual([]);
  });

  it("breaks lines at missing values and at segment changes, never using zero", () => {
    const points = chronological([
      point({ n: 5, analyzedAt: "2026-10-05T00:00:00Z", segment: "v2", scores: ["0.9000", "0.5000", "0.5000"] }),
      point({ n: 4, analyzedAt: "2026-10-04T00:00:00Z", segment: "v2", scores: ["0.8000", "0.5000", "0.5000"] }),
      point({ n: 3, analyzedAt: "2026-10-03T00:00:00Z", scores: ["0.7000", "0.5000", "0.5000"] }),
      point({ n: 2, analyzedAt: "2026-10-02T00:00:00Z", scores: [null, "0.5000", "0.5000"] }),
      point({ n: 1, analyzedAt: "2026-10-01T00:00:00Z", scores: ["0.6000", "0.5000", "0.5000"] }),
    ]);
    expect(lines(points, "dna", score("COMPLEXITY"))).toEqual([[{ index: 3, value: 80 }, { index: 4, value: 90 }]]);
    expect(markers(points, score("COMPLEXITY")).map((m) => m.index)).toEqual([0, 2, 3, 4]);
    expect(lines(points, "dna", score("STRUCTURE")).map((l) => l.length)).toEqual([3, 2]);
  });

  it("labels sources, commits and growth from server values", () => {
    const [github, middle, upload] = threeCompatible();
    expect([sourceLabel(github), sourceLabel(upload)]).toEqual(["GitHub repository", "Uploaded archive"]);
    expect(sourceLabel({ ...upload, source: { ...upload.source, origin: "REPOSITORY" } })).toBe("Repository");
    expect(shortSha("0123456789abcdef")).toBe("0123456");
    expect(shortSha(null)).toBeNull();
    expect(growthLabel(upload)).toBe("Baseline established");
    expect(growthLabel(middle)).toBe("Since the previous assessment: 1 improved · 0 regressed");
    expect(growthLabel({ ...middle, growth: null })).toBe("Growth not calculated");
    expect(growthLabel({ ...middle, growth: { ...middle.growth!, status: "INCOMPARABLE", events: [] } })).toBe("Not comparable with the previous assessment");
    expect(growthLabel({ ...middle, growth: { ...middle.growth!, events: [] } })).toBe("No meaningful changes since the previous assessment");
    expect(gapClosed(github, "FUNCTION_DESIGN")).toBe(true);
    expect(gapClosed(github, "CODE_HYGIENE")).toBe(false);
    expect(gapClosed(upload, "FUNCTION_DESIGN")).toBe(false);
  });
});
