"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { GrowthMetricType, GrowthObservation, HistoryComparison, HistoryPoint, PaginationMeta, Project } from "@/lib/api/types";
import { levelLabel } from "@/lib/competency/format";
import { formatPercent, formatScore } from "@/lib/dna/format";
import { formatDelta, growthStatusLabel, metricName, stateLabel, versionFieldLabel } from "@/lib/growth/format";
import { compareHistory, getHistory } from "@/lib/history/client";
import {
  chronological,
  dimensionScore,
  gapClosed,
  growthLabel,
  lines,
  markers,
  scoringLabel,
  segments,
  shortSha,
  sourceLabel,
} from "@/lib/history/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";
import { priorityLabel, skillGapStatusLabel } from "@/lib/skill-gap/format";

export const NOTICE =
  "Historical DNA shows each code assessment exactly as it was recorded. Stored values are never recalculated or rewritten, and assessments measured with different versions are never compared. Learning activity is context only.";

const PER_PAGE = 25;

/** The v1 DNA dimensions, with fixed categorical colors (validated default palette, slots 1–3) and marker shapes. */
const SERIES = [
  { dimension: "COMPLEXITY", name: "Complexity", stroke: "stroke-[#2a78d6] dark:stroke-[#3987e5]", fill: "fill-[#2a78d6] dark:fill-[#3987e5]", shape: "circle" },
  { dimension: "STRUCTURE", name: "Structure", stroke: "stroke-[#eb6834] dark:stroke-[#d95926]", fill: "fill-[#eb6834] dark:fill-[#d95926]", shape: "square" },
  { dimension: "CODE_HYGIENE", name: "Code hygiene", stroke: "stroke-[#1baf7a] dark:stroke-[#199e70]", fill: "fill-[#1baf7a] dark:fill-[#199e70]", shape: "diamond" },
] as const;

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "ready"; project: Project; points: HistoryPoint[]; meta: PaginationMeta };

type Comparison = { status: "idle" } | { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; comparison: HistoryComparison };

/**
 * Historical DNA of a project (Phase 20): every stored code assessment as it
 * was recorded, how its CodeDNA, competencies and skill gaps evolved, and a
 * comparison of two chosen assessments. Read-only. Every value, segment,
 * delta and status comes from the server; the browser only orders, groups
 * and draws them, and never bridges a version change or a missing value.
 */
export function HistoryView({ projectId }: { projectId: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId);
  const [page, setPage] = useState(1);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const [selected, setSelected] = useState<string[]>([]);
  const [comparison, setComparison] = useState<Comparison>({ status: "idle" });

  const handleError = useCallback(
    (error: unknown) => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      setState(isApiError(error) && error.status === 404 ? { status: "not-found" } : { status: "error", error });
    },
    [router],
  );

  const load = useCallback(() => {
    if (!valid) return;
    Promise.all([getProject(projectId), getHistory(projectId, page, PER_PAGE)])
      .then(([project, history]) => setState({ status: "ready", project, points: history.data, meta: history.meta }))
      .catch(handleError);
  }, [projectId, page, valid, handleError]);

  useEffect(() => load(), [load]);

  const goTo = (next: number) => {
    setState({ status: "loading" });
    setPage(next);
  };

  // Each selection change or request gets a new number; a comparison answering for an older one is dropped.
  const comparisons = useRef(0);
  const toggle = (id: string) => {
    comparisons.current += 1;
    setComparison({ status: "idle" });
    setSelected((current) => (current.includes(id) ? current.filter((s) => s !== id) : [...current, id].slice(-2)));
  };

  const compare = () => {
    if (selected.length !== 2) return;
    const request = ++comparisons.current;
    setComparison({ status: "loading" });
    compareHistory(projectId, selected[0], selected[1])
      .then((result) => {
        if (request === comparisons.current) setComparison({ status: "ready", comparison: result });
      })
      .catch((error: unknown) => {
        if (request !== comparisons.current) return;
        if (isApiError(error) && error.status === 401) {
          handleError(error);
          return;
        }
        setComparison({ status: "error", error });
      });
  };

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading historical DNA">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-24" />
        <Skeleton className="h-64" />
        <span className="sr-only">Loading historical DNA…</span>
      </div>
    );
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">Project not found</h1>
        <p className="text-muted-foreground">It does not exist, or it belongs to another account.</p>
        <Link href="/app/projects" className="underline underline-offset-4">
          Back to projects
        </Link>
      </div>
    );
  }

  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <ApiErrorAlert error={state.error} />
        <Button variant="outline" className="w-fit" onClick={() => (setState({ status: "loading" }), load())}>
          Try again
        </Button>
      </div>
    );
  }

  const { project, points, meta } = state;
  const ordered = chronological(points);

  return (
    <div className="grid max-w-5xl gap-6">
      <div className="grid gap-2">
        <nav aria-label="Related pages" className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <Link href={`/app/projects/${project.id}`} className="text-muted-foreground underline-offset-4 hover:underline">
            ← {project.name}
          </Link>
          <Link href={`/app/projects/${project.id}/dna`} className="text-muted-foreground underline-offset-4 hover:underline">
            CodeDNA
          </Link>
          <Link href={`/app/projects/${project.id}/growth`} className="text-muted-foreground underline-offset-4 hover:underline">
            Growth
          </Link>
          <Link href={`/app/projects/${project.id}/competencies`} className="text-muted-foreground underline-offset-4 hover:underline">
            Competency Matrix
          </Link>
          <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-muted-foreground underline-offset-4 hover:underline">
            Skill Gaps
          </Link>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Historical DNA</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl">
          What {project.name}&apos;s CodeDNA, competencies and skill gaps looked like at each code assessment. Growth shows what changed
          between two assessments; this page shows every assessment as it was recorded.
        </p>
        <p className="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950" data-testid="history-notice">
          {NOTICE}
        </p>
        {project.status === "ARCHIVED" ? (
          <p className="text-muted-foreground text-sm" data-testid="history-archived">
            This project is archived. Its history stays readable; no new analysis can be added.
          </p>
        ) : null}
      </div>

      {meta.total === 0 ? (
        <Card data-testid="history-empty">
          <CardHeader>
            <CardTitle>
              <h2>No assessments yet</h2>
            </CardTitle>
            <CardDescription>
              Historical DNA lists every completed code assessment of this project. Analyze the project&apos;s source code first; queued and
              failed analyses never appear here.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Link href={`/app/projects/${project.id}`} className="text-sm underline underline-offset-4">
              Open the project
            </Link>
          </CardContent>
        </Card>
      ) : (
        <>
          {page === 1 ? <LatestAndPrevious points={points} projectId={project.id} /> : null}
          <DnaEvolution points={ordered} total={meta.total} />
          <VersionSegments points={ordered} />
          <CompetencyEvolution points={ordered} />
          <SkillGapEvolution points={ordered} />
          <Timeline
            points={points}
            projectId={project.id}
            selected={selected}
            onToggle={toggle}
            onCompare={compare}
            comparing={comparison.status === "loading"}
          />
          {meta.last_page > 1 ? (
            <nav aria-label="History pages" className="flex items-center gap-3 text-sm" data-testid="history-pagination">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => goTo(page - 1)}>
                Newer assessments
              </Button>
              <span className="text-muted-foreground">
                Page {meta.current_page} of {meta.last_page} · {meta.total} assessments
              </span>
              <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => goTo(page + 1)}>
                Older assessments
              </Button>
            </nav>
          ) : null}
          <ComparisonPanel comparison={comparison} />
        </>
      )}

      <p className="text-muted-foreground max-w-3xl text-xs">
        Historical DNA describes measured characteristics of the analyzed source code at each assessment. Each project has its own
        history; projects are never combined. It does not infer developer seniority, ability or professional level.
      </p>
    </div>
  );
}

function SourceLine({ point }: { point: HistoryPoint }) {
  const github = point.source.github;
  return (
    <span data-testid="history-source" data-origin={point.source.origin}>
      Source: {sourceLabel(point)}
      {github !== null ? (
        <>
          {" · "}
          {github.repository ?? "repository unknown"}
          {github.ref !== null ? <> @ {github.ref}</> : null}
          {github.commit_sha !== null ? (
            <>
              {" · commit "}
              <code title={github.commit_sha}>{shortSha(github.commit_sha)}</code>
            </>
          ) : null}
        </>
      ) : null}
      {" · snapshot v"}
      {point.source.version}
    </span>
  );
}

function LatestAndPrevious({ points, projectId }: { points: HistoryPoint[]; projectId: string }) {
  const [latest, previous] = points;
  return (
    <section aria-labelledby="history-latest" className="grid gap-3" data-testid="history-latest">
      <h2 id="history-latest" className="text-lg font-semibold">
        Latest assessments
      </h2>
      <div className="grid gap-3 sm:grid-cols-2">
        <AssessmentCard title="Latest assessment" point={latest} projectId={projectId} />
        {previous !== undefined ? (
          <AssessmentCard title="Previous assessment" point={previous} projectId={projectId} />
        ) : (
          <Card data-testid="history-baseline">
            <CardHeader>
              <CardTitle>
                <h3>Previous assessment</h3>
              </CardTitle>
              <CardDescription>
                Baseline established: this is the project&apos;s first assessment. Nothing earlier exists, and no trend is drawn from a single
                assessment.
              </CardDescription>
            </CardHeader>
          </Card>
        )}
      </div>
    </section>
  );
}

function AssessmentCard({ title, point, projectId }: { title: string; point: HistoryPoint; projectId: string }) {
  const overall = formatScore(point.dna.overall_score);
  return (
    <Card data-testid="history-assessment-card">
      <CardHeader>
        <CardTitle>
          <h3>{title}</h3>
        </CardTitle>
        <CardDescription>{formatDateTime(point.analyzed_at)}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-1 text-sm">
        <p>
          CodeDNA overall: <span className="tabular-nums">{overall === null ? "Insufficient data" : `${overall} of 100`}</span>
        </p>
        <p className="text-muted-foreground">Data quality: {formatPercent(point.dna.data_quality) ?? "—"}</p>
        <p className="text-muted-foreground">{scoringLabel(point)}</p>
        <p className="text-muted-foreground">
          <SourceLine point={point} />
        </p>
        <Link href={`/app/projects/${projectId}/dna/${point.id}`} className="w-fit underline underline-offset-4">
          View its CodeDNA
        </Link>
      </CardContent>
    </Card>
  );
}

const WIDTH = 640;
const HEIGHT = 220;
const PAD = { left: 36, right: 12, top: 12, bottom: 28 };

function x(index: number, count: number): number {
  const inner = WIDTH - PAD.left - PAD.right;
  return count <= 1 ? PAD.left + inner / 2 : PAD.left + (index * inner) / (count - 1);
}

function y(value: number): number {
  return PAD.top + (1 - value / 100) * (HEIGHT - PAD.top - PAD.bottom);
}

function Marker({ shape, cx, cy, className, label }: { shape: string; cx: number; cy: number; className: string; label?: string }) {
  const ring = "stroke-background";
  const title = label === undefined ? null : <title>{label}</title>;
  if (shape === "square") {
    return (
      <rect x={cx - 4.5} y={cy - 4.5} width={9} height={9} rx={1.5} className={`${className} ${ring}`} strokeWidth={2} data-testid="history-marker">
        {title}
      </rect>
    );
  }
  if (shape === "diamond") {
    return (
      <polygon points={`${cx},${cy - 6} ${cx + 6},${cy} ${cx},${cy + 6} ${cx - 6},${cy}`} className={`${className} ${ring}`} strokeWidth={2} data-testid="history-marker">
        {title}
      </polygon>
    );
  }
  return (
    <circle cx={cx} cy={cy} r={5} className={`${className} ${ring}`} strokeWidth={2} data-testid="history-marker">
      <title>{label}</title>
    </circle>
  );
}

/**
 * One line per DNA dimension on a fixed 0–100 axis, oldest on the left.
 * Lines never cross a scoring segment or a missing value; one assessment is
 * a baseline, not a trend.
 */
function DnaEvolution({ points, total }: { points: HistoryPoint[]; total: number }) {
  const segs = segments(points, "dna");
  const drawn = SERIES.map((s) => ({ ...s, lines: lines(points, "dna", (p) => dimensionScore(p, s.dimension)), markers: markers(points, (p) => dimensionScore(p, s.dimension)) }));
  const anyValue = drawn.some((s) => s.markers.length > 0);
  const anyLine = drawn.some((s) => s.lines.length > 0);
  return (
    <section aria-labelledby="history-dna" className="grid gap-3" data-testid="history-dna">
      <h2 id="history-dna" className="text-lg font-semibold">
        DNA evolution
      </h2>
      {total === 1 ? (
        <p className="text-sm" data-testid="history-baseline-message">
          Baseline established. A trend needs at least two comparable assessments; the next code assessment will start one.
        </p>
      ) : !anyLine ? (
        <p className="text-sm" data-testid="history-no-trend">
          No historical trend available: there are no two consecutive assessments measured with the same versions and with values for the
          same dimension.
        </p>
      ) : null}
      {anyValue ? (
        <figure className="grid gap-2 rounded-lg border p-3">
          <figcaption className="text-muted-foreground text-sm">
            DNA dimension scores from 0 to 100 at each assessment on this page, oldest on the left. A dashed divider marks a change of
            scoring version; lines never cross it.
          </figcaption>
          <ul className="flex flex-wrap gap-4 text-sm" aria-label="Legend" data-testid="history-legend">
            {SERIES.map((s) => (
              <li key={s.dimension} className="flex items-center gap-2">
                <svg width="14" height="14" aria-hidden="true">
                  <Marker shape={s.shape} cx={7} cy={7} className={s.fill} />
                </svg>
                {s.name}
              </li>
            ))}
          </ul>
          <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} className="w-full" role="img" aria-label="DNA dimension scores over time" data-testid="history-chart">
            {[0, 25, 50, 75, 100].map((v) => (
              <g key={v}>
                <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y(v)} y2={y(v)} className="stroke-muted" strokeWidth={1} />
                <text x={PAD.left - 6} y={y(v) + 4} textAnchor="end" className="fill-muted-foreground text-[10px]">
                  {v}
                </text>
              </g>
            ))}
            {segs.slice(1).map((seg) => {
              const at = (x(seg.indexes[0] - 1, points.length) + x(seg.indexes[0], points.length)) / 2;
              return (
                <line
                  key={`div-${seg.indexes[0]}`}
                  x1={at}
                  x2={at}
                  y1={PAD.top}
                  y2={HEIGHT - PAD.bottom}
                  className="stroke-muted-foreground"
                  strokeDasharray="4 4"
                  strokeWidth={1}
                  data-testid="history-segment-divider"
                />
              );
            })}
            {drawn.map((s) =>
              s.lines.map((line) => (
                <polyline
                  key={`${s.dimension}-${line[0].index}`}
                  points={line.map((p) => `${x(p.index, points.length).toFixed(1)},${y(p.value).toFixed(1)}`).join(" ")}
                  fill="none"
                  className={s.stroke}
                  strokeWidth={2}
                  strokeLinejoin="round"
                  strokeLinecap="round"
                  data-testid="history-line"
                  data-series={s.dimension}
                />
              )),
            )}
            {drawn.map((s) =>
              s.markers.map((p) => (
                <Marker
                  key={`${s.dimension}-m-${p.index}`}
                  shape={s.shape}
                  cx={x(p.index, points.length)}
                  cy={y(p.value)}
                  className={s.fill}
                  label={`${s.name}: ${p.value.toFixed(2)} of 100 · ${formatDateTime(points[p.index].analyzed_at)}`}
                />
              )),
            )}
          </svg>
        </figure>
      ) : null}
    </section>
  );
}

function VersionSegments({ points }: { points: HistoryPoint[] }) {
  const segs = segments(points, "dna");
  return (
    <section aria-labelledby="history-segments" className="grid gap-2" data-testid="history-segments">
      <h2 id="history-segments" className="text-lg font-semibold">
        Version segments
      </h2>
      <p className="text-muted-foreground text-sm">Assessments measured with the same scoring versions. Values are compared only within a segment.</p>
      <ol className="grid gap-2 text-sm">
        {[...segs].reverse().map((seg) => {
          const first = points[seg.indexes[0]];
          const last = points[seg.indexes[seg.indexes.length - 1]];
          return (
            <li key={`${seg.key}-${seg.indexes[0]}`} className="rounded-lg border p-3" data-testid="history-segment">
              <span className="font-medium">{scoringLabel(first)}</span>
              <span className="text-muted-foreground">
                {" · "}
                {seg.indexes.length} assessment{seg.indexes.length === 1 ? "" : "s"} · {formatDateTime(first.analyzed_at)}
                {seg.indexes.length > 1 ? ` – ${formatDateTime(last.analyzed_at)}` : ""}
              </span>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

function HeaderCells({ points }: { points: HistoryPoint[] }) {
  return (
    <>
      {points.map((p) => (
        <th key={p.id} scope="col" className="py-2 pr-4 font-medium whitespace-nowrap">
          {formatDateTime(p.analyzed_at)}
        </th>
      ))}
    </>
  );
}

/** Stored scores and categorical levels per assessment; a level is never treated as a number. */
function CompetencyEvolution({ points }: { points: HistoryPoint[] }) {
  const keys = Array.from(new Set(points.flatMap((p) => p.competency?.competencies.map((c) => c.key) ?? [])));
  return (
    <section aria-labelledby="history-competencies" className="grid gap-3" data-testid="history-competencies">
      <h2 id="history-competencies" className="text-lg font-semibold">
        Competency evolution
      </h2>
      {keys.length === 0 ? (
        <p className="text-muted-foreground text-sm">No competency matrix is available for these assessments.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b">
                <th scope="col" className="py-2 pr-4 font-medium">
                  Competency
                </th>
                <HeaderCells points={points} />
              </tr>
            </thead>
            <tbody>
              {keys.map((key) => (
                <tr key={key} className="border-b last:border-0" data-testid="history-competency-row" data-key={key}>
                  <th scope="row" className="py-2 pr-4 font-normal">
                    {metricName("COMPETENCY", key)}
                  </th>
                  {points.map((p) => {
                    if (p.competency === null) {
                      return (
                        <td key={p.id} className="text-muted-foreground py-2 pr-4" data-testid="history-unavailable">
                          Unavailable
                        </td>
                      );
                    }
                    const c = p.competency.competencies.find((entry) => entry.key === key);
                    const score = formatScore(c?.score ?? null);
                    return (
                      <td key={p.id} className="py-2 pr-4">
                        <span className="tabular-nums">{score ?? stateLabel("COMPETENCY", c?.status ?? "MISSING")}</span>
                        {levelLabel(c?.level ?? null) !== null ? <span className="text-muted-foreground block text-xs">{levelLabel(c?.level ?? null)}</span> : null}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <LevelTransitions points={points} />
    </section>
  );
}

/** Level transitions recorded by Phase 18 growth, listed as observations without a cause. */
function LevelTransitions({ points }: { points: HistoryPoint[] }) {
  const transitions = points.flatMap((p) =>
    (p.growth?.events ?? [])
      .filter((e) => e.kind === "LEVEL_UP" || e.kind === "LEVEL_DOWN")
      .map((e) => ({ point: p, event: e })),
  );
  if (transitions.length === 0) return null;
  return (
    <ul className="grid gap-1 text-sm" data-testid="history-level-transitions">
      {transitions.map(({ point, event }) => (
        <li key={`${point.id}-${event.metric_key}`}>
          {formatDateTime(point.analyzed_at)} · {metricName("COMPETENCY", event.metric_key)}: {levelLabel(event.previous_level ?? null) ?? "—"} →{" "}
          {levelLabel(event.current_level ?? null) ?? "—"}
        </li>
      ))}
    </ul>
  );
}

/** Each competency's stored gap state per assessment. Gaps are never merged into an overall gap. */
function SkillGapEvolution({ points }: { points: HistoryPoint[] }) {
  const keys = Array.from(new Set(points.flatMap((p) => p.skill_gaps?.results.map((r) => r.competency_key) ?? [])));
  return (
    <section aria-labelledby="history-skill-gaps" className="grid gap-3" data-testid="history-skill-gaps">
      <h2 id="history-skill-gaps" className="text-lg font-semibold">
        Skill-gap evolution
      </h2>
      <p className="text-muted-foreground text-sm">The gap to the target at each assessment, in points of 100: lower is better.</p>
      {keys.length === 0 ? (
        <p className="text-muted-foreground text-sm">No skill gap analysis is available for these assessments.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b">
                <th scope="col" className="py-2 pr-4 font-medium">
                  Competency
                </th>
                <HeaderCells points={points} />
              </tr>
            </thead>
            <tbody>
              {keys.map((key) => (
                <tr key={key} className="border-b last:border-0" data-testid="history-gap-row" data-key={key}>
                  <th scope="row" className="py-2 pr-4 font-normal">
                    {metricName("SKILL_GAP", key)}
                  </th>
                  {points.map((p) => {
                    if (p.skill_gaps === null) {
                      return (
                        <td key={p.id} className="text-muted-foreground py-2 pr-4" data-testid="history-unavailable">
                          Unavailable
                        </td>
                      );
                    }
                    const r = p.skill_gaps.results.find((entry) => entry.competency_key === key);
                    const gap = formatScore(r?.gap ?? null);
                    return (
                      <td key={p.id} className="py-2 pr-4" data-testid="history-gap-cell" data-status={r?.status ?? "MISSING"}>
                        <span>{skillGapStatusLabel(r?.status ?? "MISSING")}</span>
                        {gap !== null && r?.status === "GAP" ? <span className="block tabular-nums">Gap {gap}</span> : null}
                        {priorityLabel(r?.priority ?? null) !== null ? <span className="text-muted-foreground block text-xs">{priorityLabel(r?.priority ?? null)}</span> : null}
                        {gapClosed(p, key) ? (
                          <span className="block text-xs font-medium" data-testid="history-gap-resolved">
                            Resolved since the previous assessment
                          </span>
                        ) : null}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Timeline({
  points,
  projectId,
  selected,
  onToggle,
  onCompare,
  comparing,
}: {
  points: HistoryPoint[];
  projectId: string;
  selected: string[];
  onToggle: (id: string) => void;
  onCompare: () => void;
  comparing: boolean;
}) {
  return (
    <section aria-labelledby="history-timeline" className="grid gap-3" data-testid="history-timeline">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="history-timeline" className="text-lg font-semibold">
          Assessment timeline
        </h2>
        <Button size="sm" disabled={selected.length !== 2 || comparing} onClick={onCompare} data-testid="history-compare">
          {comparing ? "Comparing…" : "Compare selected"}
        </Button>
      </div>
      <p className="text-muted-foreground text-sm">Newest first. Select two assessments to compare them.</p>
      <ol className="grid gap-2">
        {points.map((p) => {
          const overall = formatScore(p.dna.overall_score);
          return (
            <li key={p.id} className="grid gap-1 rounded-lg border p-3 text-sm" data-testid="history-item" data-id={p.id}>
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <label className="flex items-center gap-2 font-medium">
                  <input
                    type="checkbox"
                    checked={selected.includes(p.id)}
                    onChange={() => onToggle(p.id)}
                    aria-label={`Select the assessment of ${formatDateTime(p.analyzed_at)} for comparison`}
                  />
                  {formatDateTime(p.analyzed_at)}
                </label>
                <span className="tabular-nums">CodeDNA {overall ?? "insufficient data"}</span>
              </div>
              <p className="text-muted-foreground">
                {p.dna.dimensions.map((d) => `${d.name} ${formatScore(d.score) ?? (d.status === "MISSING" ? "not measured" : "unavailable")}`).join(" · ")}
                {" · data quality "}
                {formatPercent(p.dna.data_quality) ?? "—"}
              </p>
              <p className="text-muted-foreground">
                {scoringLabel(p)}
                {p.layers.competency === "UNAVAILABLE" ? " · competency matrix unavailable" : ""}
                {p.layers.competency === "AVAILABLE" && p.layers.skill_gaps === "UNAVAILABLE" ? " · skill gaps unavailable" : ""}
              </p>
              <p className="text-muted-foreground">
                <SourceLine point={p} />
              </p>
              <p data-testid="history-growth">
                {growthLabel(p)}
                {p.growth !== null ? (
                  <>
                    {" · "}
                    <Link href={`/app/projects/${projectId}/growth?snapshot=${p.growth.id}`} className="underline underline-offset-4">
                      View growth
                    </Link>
                  </>
                ) : null}
                {" · "}
                <Link href={`/app/projects/${projectId}/dna/${p.id}`} className="underline underline-offset-4">
                  View CodeDNA
                </Link>
              </p>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

const LAYERS: { key: "dna" | "competency" | "skill_gaps"; type: GrowthMetricType; title: string; field: "dna" | "competencies" | "skill_gaps" }[] = [
  { key: "dna", type: "DNA", title: "CodeDNA", field: "dna" },
  { key: "competency", type: "COMPETENCY", title: "Competencies", field: "competencies" },
  { key: "skill_gaps", type: "SKILL_GAP", title: "Skill gaps (lower is better)", field: "skill_gaps" },
];

function ComparisonPanel({ comparison }: { comparison: Comparison }) {
  if (comparison.status === "idle") return null;
  if (comparison.status === "loading") {
    return <Skeleton className="h-32" role="status" aria-label="Comparing assessments" />;
  }
  if (comparison.status === "error") {
    return <ApiErrorAlert error={comparison.error} />;
  }
  const c = comparison.comparison;
  return (
    <section aria-labelledby="history-comparison" className="grid gap-3 rounded-lg border p-4" data-testid="history-comparison" data-status={c.status}>
      <h2 id="history-comparison" className="text-lg font-semibold">
        Comparison
      </h2>
      <p className="text-sm">
        {formatDateTime(c.from.analyzed_at)} → {formatDateTime(c.to.analyzed_at)}
      </p>
      {c.status === "INCOMPARABLE" ? (
        <div className="grid gap-2" data-testid="history-incomparable">
          <p className="font-medium">Not comparable</p>
          <p className="text-muted-foreground text-sm">
            These assessments were measured with different versions, so no difference is shown. This is not a regression.
          </p>
          <ul className="list-disc pl-5 text-sm">
            {c.differences.map((field) => (
              <li key={field}>
                {versionFieldLabel(field)}: {c.from.versions[field] ?? "—"} → {c.to.versions[field] ?? "—"}
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <>
          <p className="text-muted-foreground text-sm" data-testid="history-basis">
            {c.basis === "GROWTH_SNAPSHOT"
              ? "These are adjacent assessments: the values below are the stored growth record."
              : `Compared with growth rules ${c.rules.version} over the stored values. Nothing was saved.`}
          </p>
          {LAYERS.map((layer) =>
            c.layers[layer.key] === "UNAVAILABLE" ? (
              <p key={layer.key} className="text-muted-foreground text-sm" data-testid="history-layer-unavailable">
                {layer.title}: unavailable for at least one of the two assessments, so not compared.
              </p>
            ) : (
              <ObservationTable key={layer.key} title={layer.title} type={layer.type} observations={c[layer.field]} />
            ),
          )}
        </>
      )}
      <div className="grid gap-1 rounded-lg border border-dashed p-3 text-sm" data-testid="history-activity">
        <p className="font-medium">Learning activity (context only)</p>
        <p>
          Between these assessments: {c.activity.roadmap_steps_completed} learning step{c.activity.roadmap_steps_completed === 1 ? "" : "s"}{" "}
          completed and {c.activity.challenges_passed} challenge{c.activity.challenges_passed === 1 ? "" : "s"} passed.
        </p>
        <p className="text-muted-foreground text-xs">
          Learning activity is not evidence and does not explain any value here. Only code analysis measures the code.
        </p>
      </div>
    </section>
  );
}

function ObservationTable({ title, type, observations }: { title: string; type: GrowthMetricType; observations: GrowthObservation[] }) {
  return (
    <div className="grid gap-2" data-testid="history-comparison-layer" data-type={type}>
      <h3 className="font-medium">{title}</h3>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b">
              <th scope="col" className="py-1 pr-4 font-medium">
                Metric
              </th>
              <th scope="col" className="py-1 pr-4 font-medium">
                Earlier
              </th>
              <th scope="col" className="py-1 pr-4 font-medium">
                Later
              </th>
              <th scope="col" className="py-1 pr-4 font-medium">
                Difference
              </th>
              <th scope="col" className="py-1 pr-4 font-medium">
                Outcome
              </th>
            </tr>
          </thead>
          <tbody>
            {observations.map((o) => (
              <tr key={o.metric_key} className="border-b last:border-0" data-testid="history-observation" data-metric={`${type}:${o.metric_key}`}>
                <th scope="row" className="py-1 pr-4 font-normal">
                  {metricName(type, o.metric_key)}
                </th>
                <td className="py-1 pr-4 tabular-nums">{formatScore(o.previous_value) ?? stateLabel(type, o.previous_state)}</td>
                <td className="py-1 pr-4 tabular-nums">{formatScore(o.current_value) ?? stateLabel(type, o.current_state)}</td>
                <td className="py-1 pr-4 tabular-nums" data-testid="history-delta">
                  {formatDelta(o.delta) ?? "—"}
                </td>
                <td className="py-1 pr-4">
                  {growthStatusLabel(o.status)}
                  {o.level_change === "UP" || o.level_change === "DOWN" ? (
                    <span className="text-muted-foreground block text-xs">
                      Level {levelLabel(o.previous_level) ?? "—"} → {levelLabel(o.current_level) ?? "—"}
                    </span>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
