import type { HistoryPoint } from "@/lib/api/types";
import { formatScore } from "@/lib/dna/format";

/**
 * Display helpers for historical DNA (Phase 20). They order and group the
 * server's points and reformat its decimal strings digit by digit. No score,
 * gap, delta or compatibility is computed here: segments are the server's
 * keys, and a trend line is only ever drawn between consecutive points
 * whose key is equal and whose values were all measured.
 */

export type HistoryLayer = "dna" | "competency" | "skill_gaps";

/** Oldest first (the API returns newest first). */
export function chronological(points: HistoryPoint[]): HistoryPoint[] {
  return [...points].reverse();
}

export interface Segment {
  key: string;
  /** Indexes into the chronological list. */
  indexes: number[];
}

/**
 * Consecutive runs of points (oldest first) measured alike for one layer.
 * A point without the layer belongs to no segment and ends the run.
 */
export function segments(chronologicalPoints: HistoryPoint[], layer: HistoryLayer): Segment[] {
  const result: Segment[] = [];
  let current: Segment | null = null;
  chronologicalPoints.forEach((point, index) => {
    const key = point.segments[layer];
    if (key === null) {
      current = null;
      return;
    }
    if (current === null || current.key !== key) {
      current = { key, indexes: [] };
      result.push(current);
    }
    current.indexes.push(index);
  });
  return result;
}

export interface PlotPoint {
  index: number;
  /** 0–100, from the stored decimal string. */
  value: number;
}

/**
 * The lines to draw for one series: runs of two or more consecutive measured
 * points inside one segment. A missing value or a segment change breaks the
 * line; it is never bridged, and never drawn at zero.
 */
export function lines(chronologicalPoints: HistoryPoint[], layer: HistoryLayer, value: (point: HistoryPoint) => string | null): PlotPoint[][] {
  const result: PlotPoint[][] = [];
  for (const segment of segments(chronologicalPoints, layer)) {
    let run: PlotPoint[] = [];
    for (const index of segment.indexes) {
      const score = formatScore(value(chronologicalPoints[index]));
      if (score === null) {
        if (run.length >= 2) result.push(run);
        run = [];
        continue;
      }
      run.push({ index, value: Number(score) });
    }
    if (run.length >= 2) result.push(run);
  }
  return result;
}

/** Every measured value of a series, for markers (no line implied). */
export function markers(chronologicalPoints: HistoryPoint[], value: (point: HistoryPoint) => string | null): PlotPoint[] {
  const result: PlotPoint[] = [];
  chronologicalPoints.forEach((point, index) => {
    const score = formatScore(value(point));
    if (score !== null) result.push({ index, value: Number(score) });
  });
  return result;
}

export function dimensionScore(point: HistoryPoint, dimension: string): string | null {
  return point.dna.dimensions.find((d) => d.dimension === dimension)?.score ?? null;
}

export function sourceLabel(point: HistoryPoint): string {
  switch (point.source.origin) {
    case "GITHUB":
      return "GitHub repository";
    case "UPLOAD":
      return "Uploaded archive";
    default:
      return "Repository";
  }
}

/** "0123456789abcdef…" -> "0123456". */
export function shortSha(sha: string | null): string | null {
  return sha === null ? null : sha.slice(0, 7);
}

/** How the server's segment for the DNA layer reads. */
export function scoringLabel(point: HistoryPoint): string {
  return `Scoring v${point.dna.scoring_version}${point.dna.metrics_version === null ? "" : ` · metrics ${point.dna.metrics_version}`}`;
}

/** The stored Phase 18 growth of a point, in a few words. */
export function growthLabel(point: HistoryPoint): string {
  const growth = point.growth;
  if (point.layers.skill_gaps === "UNAVAILABLE") return "No growth record (assessment incomplete)";
  if (growth === null) return "Growth not calculated";
  if (growth.status === "NOT_ESTABLISHED") return "Baseline established";
  if (growth.status === "INCOMPARABLE") return "Not comparable with the previous assessment";
  const improved = growth.events.filter((e) => e.kind === "IMPROVED" || e.kind === "GAP_CLOSED").length;
  const regressed = growth.events.filter((e) => e.kind === "REGRESSED" || e.kind === "GAP_OPENED").length;
  if (improved === 0 && regressed === 0) return "No meaningful changes since the previous assessment";
  return `Since the previous assessment: ${improved} improved · ${regressed} regressed`;
}

/** Whether the point's stored growth records this gap closing (Phase 18 event). */
export function gapClosed(point: HistoryPoint, competencyKey: string): boolean {
  return point.growth?.events.some((e) => e.kind === "GAP_CLOSED" && e.metric_key === competencyKey) ?? false;
}
