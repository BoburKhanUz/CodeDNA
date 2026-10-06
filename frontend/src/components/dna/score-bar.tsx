import { barWidth, formatScore } from "@/lib/dna/format";
import type { DecimalString } from "@/lib/api/types";

/**
 * A neutral 0–100 bar for a backend score. No color scale and no labels such
 * as "good" or "weak": the bar only shows where the value sits. Without a
 * value it renders an empty track (never a bar at 0).
 */
export function ScoreBar({ value, label }: { value: DecimalString | null; label: string }) {
  const score = formatScore(value);
  if (score === null) {
    return <div aria-hidden="true" className="bg-muted h-2 w-full rounded-full border border-dashed" />;
  }
  return (
    <div
      role="meter"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Number(score)}
      aria-valuetext={`${score} of 100`}
      className="bg-muted h-2 w-full overflow-hidden rounded-full"
    >
      <div className="bg-primary h-full rounded-full" style={{ width: barWidth(value) }} />
    </div>
  );
}
