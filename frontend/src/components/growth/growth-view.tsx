"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { type ReactNode, useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { ScoreBar } from "@/components/dna/score-bar";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type {
  GrowthMetricType,
  GrowthObservation,
  GrowthOverview,
  GrowthSeries,
  GrowthSnapshot,
  GrowthSnapshotSummary,
  GrowthState,
  GrowthStatus,
  Project,
} from "@/lib/api/types";
import { levelLabel } from "@/lib/competency/format";
import { formatPercent, formatScore } from "@/lib/dna/format";
import { getGrowth, getGrowthSnapshot, getGrowthTimeline } from "@/lib/growth/client";
import { eventLabel, formatDelta, growthStatusLabel, metricName, metricTypeLabel, stateLabel, versionFieldLabel } from "@/lib/growth/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";

export const NOTICE =
  "Growth compares deterministic code assessments only. Completed learning steps and challenges are not growth evidence; only a new code analysis can show change.";

/** The documented meaningful difference (growth rules 1.0.0), shown for explanation only. */
const THRESHOLD_POINTS = "5.00";

type State =
  | { status: "loading" }
  | { status: "not-found"; what: "project" | "snapshot" }
  | { status: "error"; error: unknown }
  | { status: "ready"; project: Project; overview: GrowthOverview; timeline: GrowthSnapshotSummary[]; snapshot: GrowthSnapshot | null };

const STATUSES: GrowthStatus[] = ["IMPROVED", "REGRESSED", "UNCHANGED", "INSUFFICIENT_EVIDENCE"];
const TYPES: GrowthMetricType[] = ["DNA", "COMPETENCY", "SKILL_GAP"];

/**
 * Growth of a project (Phase 18): how the newest code assessment compares
 * with the one immediately before it. Read-only, and an observation layer
 * only: every value, delta and status comes from stored growth snapshots,
 * nothing is compared in the browser, and learning activity is shown as
 * context, never as evidence.
 */
export function GrowthView({ projectId, snapshotId }: { projectId: string; snapshotId?: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId) && (snapshotId === undefined || isProjectId(snapshotId));
  const [state, setState] = useState<State>(() =>
    valid ? { status: "loading" } : { status: "not-found", what: isProjectId(projectId) ? "snapshot" : "project" },
  );

  const handleError = useCallback(
    (error: unknown) => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      setState(isApiError(error) && error.status === 404 ? { status: "not-found", what: "project" } : { status: "error", error });
    },
    [router],
  );

  const load = useCallback(() => {
    if (!valid) return;
    fetchGrowth(projectId, snapshotId).then(setState).catch(handleError);
  }, [projectId, snapshotId, valid, handleError]);

  useEffect(() => load(), [load]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading growth">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-24" />
        <Skeleton className="h-64" />
        <span className="sr-only">Loading growth…</span>
      </div>
    );
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{state.what === "project" ? "Project not found" : "Growth record not found"}</h1>
        <p className="text-muted-foreground">It does not exist, or it belongs to another account.</p>
        <Link href={state.what === "project" ? "/app/projects" : `/app/projects/${projectId}/growth`} className="underline underline-offset-4">
          {state.what === "project" ? "Back to projects" : "Back to growth"}
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

  const { project, overview, timeline, snapshot } = state;
  const selected = snapshotId !== undefined;
  const superseded = selected && snapshot !== null && snapshot.id !== overview.latest?.id;
  const shown: GrowthState = selected && snapshot !== null ? snapshot.status : overview.state;

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
          <Link href={`/app/projects/${project.id}/competencies`} className="text-muted-foreground underline-offset-4 hover:underline">
            Competency Matrix
          </Link>
          <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-muted-foreground underline-offset-4 hover:underline">
            Skill Gaps
          </Link>
          <Link href={`/app/projects/${project.id}/roadmap`} className="text-muted-foreground underline-offset-4 hover:underline">
            Learning Roadmap
          </Link>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Growth</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl">
          How {project.name}&apos;s newest code assessment compares with the assessment immediately before it: CodeDNA dimensions,
          competencies and skill gaps, measured by the same versions.
        </p>
        <p className="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950" data-testid="growth-notice">
          {NOTICE}
        </p>
        {project.status === "ARCHIVED" ? (
          <p className="text-muted-foreground text-sm" data-testid="growth-archived">
            This project is archived. Its growth history stays readable; no new analysis can be added.
          </p>
        ) : null}
      </div>

      {superseded ? (
        <p className="rounded-lg border p-3 text-sm" data-testid="growth-superseded">
          You are viewing an earlier assessment. A newer assessment exists.{" "}
          <Link href={`/app/projects/${project.id}/growth`} className="underline underline-offset-4">
            View the newest growth
          </Link>
        </p>
      ) : null}

      <StateCard state={shown} snapshot={snapshot} projectId={project.id} />

      {snapshot !== null ? (
        <>
          {snapshot.status === "COMPARED" ? <Summary snapshot={snapshot} /> : null}
          <Assessments snapshot={snapshot} projectId={project.id} />
          {snapshot.status === "COMPARED" ? (
            <>
              <Events snapshot={snapshot} />
              <ObservationSection title="CodeDNA dimensions" type="DNA" observations={snapshot.dna} testId="growth-dna" />
              <ObservationSection title="Competencies" type="COMPETENCY" observations={snapshot.competencies} testId="growth-competencies" />
              <ObservationSection title="Skill gaps" type="SKILL_GAP" observations={snapshot.skill_gaps} testId="growth-skill-gaps" />
            </>
          ) : null}
          {!selected ? <Trend series={overview.series} /> : null}
          <Activity snapshot={snapshot} />
        </>
      ) : null}

      <Timeline timeline={timeline} projectId={project.id} selectedId={snapshot?.id ?? null} />
      {snapshot !== null ? <Provenance snapshot={snapshot} /> : null}

      <p className="text-muted-foreground max-w-3xl text-xs">
        Growth describes changes in measured characteristics of the analyzed source code between two assessments. It does not
        infer developer seniority, ability or professional level, and it is not a combined growth score.
      </p>
    </div>
  );
}

async function fetchGrowth(projectId: string, snapshotId: string | undefined): Promise<State> {
  const [project, overview, timeline] = await Promise.all([getProject(projectId), getGrowth(projectId), getGrowthTimeline(projectId, 1, 10)]);
  if (snapshotId === undefined) {
    return { status: "ready", project, overview, timeline: timeline.data, snapshot: overview.latest };
  }
  try {
    return { status: "ready", project, overview, timeline: timeline.data, snapshot: await getGrowthSnapshot(projectId, snapshotId) };
  } catch (error) {
    if (isApiError(error) && error.status === 404) {
      return { status: "not-found", what: "snapshot" };
    }
    throw error;
  }
}

function count(snapshot: GrowthSnapshot, status: GrowthStatus): number {
  return TYPES.reduce((sum, type) => sum + (snapshot.summary.statuses[type]?.[status] ?? 0), 0);
}

function StateCard({ state, snapshot, projectId }: { state: GrowthState; snapshot: GrowthSnapshot | null; projectId: string }) {
  let title: string;
  let description: string;
  let extra: ReactNode = null;
  switch (state) {
    case "NO_ASSESSMENT":
      title = "No assessment yet";
      description =
        "Growth is measured between code assessments. Analyze the project's source code first; the first assessment becomes the baseline for the next one.";
      extra = (
        <Link href={`/app/projects/${projectId}`} className="text-sm underline underline-offset-4">
          Open the project
        </Link>
      );
      break;
    case "NOT_CALCULATED":
      title = "Growth not calculated yet";
      description =
        "The newest code assessment has no growth record yet. It is normally created right after the analysis. The most recent recorded growth is shown below.";
      break;
    case "NOT_ESTABLISHED":
      title = "Baseline not established";
      description =
        "This is the first code assessment of the project, so there is nothing to compare it with. It is not treated as zero. After the next code assessment, growth compares the two.";
      break;
    case "INCOMPARABLE":
      title = "No comparable assessment";
      description =
        "The previous assessment was measured with different versions, so its values cannot be compared and no change is shown. This is not a regression.";
      extra = snapshot !== null && snapshot.differences.length > 0 ? (
        <ul className="list-disc pl-5 text-sm" data-testid="growth-differences">
          {snapshot.differences.map((field) => (
            <li key={field}>
              {versionFieldLabel(field)}: {snapshot.previous_versions?.[field] ?? "—"} → {snapshot.versions[field] ?? "—"}
            </li>
          ))}
        </ul>
      ) : null;
      break;
    case "COMPARED": {
      const total = snapshot?.summary.observations ?? 0;
      const insufficient = snapshot === null ? 0 : count(snapshot, "INSUFFICIENT_EVIDENCE");
      const changed = snapshot === null ? 0 : count(snapshot, "IMPROVED") + count(snapshot, "REGRESSED");
      const levels = snapshot === null ? 0 : snapshot.summary.level_changes.UP + snapshot.summary.level_changes.DOWN;
      if (total > 0 && insufficient === total) {
        title = "Insufficient evidence";
        description =
          "The two assessments do not have enough measured evidence to compare. No change is claimed; this is not a regression.";
      } else if (changed === 0 && levels === 0) {
        title = "No meaningful changes detected";
        description = `Every measured difference between the two assessments is smaller than ${THRESHOLD_POINTS} points of 100, and no skill gap or level changed.`;
      } else {
        title = "Changes since the previous assessment";
        description = `Differences of at least ${THRESHOLD_POINTS} points of 100, skill gaps that opened or closed, and level changes between the two code assessments.`;
      }
      break;
    }
  }

  return (
    <Card data-testid="growth-state" data-state={state}>
      <CardHeader>
        <CardTitle>
          <h2>{title}</h2>
        </CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      {extra !== null ? <CardContent>{extra}</CardContent> : null}
    </Card>
  );
}

/** Categorical counts only: there is no combined growth score. */
function Summary({ snapshot }: { snapshot: GrowthSnapshot }) {
  return (
    <section aria-labelledby="growth-summary" className="grid gap-3" data-testid="growth-summary">
      <h2 id="growth-summary" className="text-lg font-semibold">
        Summary
      </h2>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b">
              <th scope="col" className="py-2 pr-4 font-medium">
                Area
              </th>
              {STATUSES.map((status) => (
                <th key={status} scope="col" className="py-2 pr-4 font-medium">
                  {growthStatusLabel(status)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {TYPES.map((type) => (
              <tr key={type} className="border-b last:border-0">
                <th scope="row" className="py-2 pr-4 font-normal">
                  {metricTypeLabel(type)}
                </th>
                {STATUSES.map((status) => (
                  <td key={status} className="py-2 pr-4 tabular-nums">
                    {snapshot.summary.statuses[type]?.[status] ?? 0}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-muted-foreground text-sm">
        Competency levels: {snapshot.summary.level_changes.UP} rose, {snapshot.summary.level_changes.DOWN} fell.
      </p>
    </section>
  );
}

function Assessments({ snapshot, projectId }: { snapshot: GrowthSnapshot; projectId: string }) {
  return (
    <section aria-labelledby="growth-assessments" className="grid gap-3" data-testid="growth-assessments">
      <h2 id="growth-assessments" className="text-lg font-semibold">
        Assessments compared
      </h2>
      <div className="grid gap-3 sm:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>
              <h3>Previous assessment</h3>
            </CardTitle>
            <CardDescription>
              {snapshot.previous === null ? "Baseline not established" : formatDateTime(snapshot.previous_assessed_at)}
            </CardDescription>
          </CardHeader>
          {snapshot.previous !== null ? (
            <CardContent>
              <Link href={`/app/projects/${projectId}/dna/${snapshot.previous.dna_snapshot_id}`} className="text-sm underline underline-offset-4">
                View its CodeDNA
              </Link>
            </CardContent>
          ) : null}
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>
              <h3>Latest assessment</h3>
            </CardTitle>
            <CardDescription>{formatDateTime(snapshot.assessed_at)}</CardDescription>
          </CardHeader>
          <CardContent>
            <Link href={`/app/projects/${projectId}/dna/${snapshot.current.dna_snapshot_id}`} className="text-sm underline underline-offset-4">
              View its CodeDNA
            </Link>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}

function Events({ snapshot }: { snapshot: GrowthSnapshot }) {
  return (
    <section aria-labelledby="growth-events" className="grid gap-3" data-testid="growth-events">
      <h2 id="growth-events" className="text-lg font-semibold">
        Changes
      </h2>
      {snapshot.events.length === 0 ? (
        <p className="text-muted-foreground text-sm">No meaningful changes detected.</p>
      ) : (
        <ul className="grid gap-1 text-sm">
          {snapshot.events.map((event, i) => (
            <li key={`${event.kind}-${event.metric_type}-${event.metric_key}-${i}`}>
              {eventLabel(event.kind, event.metric_type, event.metric_key, event.previous_level, event.current_level)}
              {event.kind !== "LEVEL_UP" && event.kind !== "LEVEL_DOWN" && formatDelta(event.delta) !== null ? (
                <span className="text-muted-foreground"> ({formatDelta(event.delta)} points)</span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function ObservationSection({
  title,
  type,
  observations,
  testId,
}: {
  title: string;
  type: GrowthMetricType;
  observations: GrowthObservation[];
  testId: string;
}) {
  const id = `${testId}-heading`;
  return (
    <section aria-labelledby={id} className="grid gap-3" data-testid={testId}>
      <h2 id={id} className="text-lg font-semibold">
        {title}
      </h2>
      {type === "SKILL_GAP" ? <p className="text-muted-foreground text-sm">For skill gaps, lower is better: a smaller gap to the target.</p> : null}
      <div className="grid gap-3 sm:grid-cols-2">
        {observations.map((o) => (
          <ObservationCard key={o.metric_key} type={type} observation={o} />
        ))}
      </div>
    </section>
  );
}

function ObservationCard({ type, observation: o }: { type: GrowthMetricType; observation: GrowthObservation }) {
  const name = metricName(type, o.metric_key);
  const measured = o.previous_value !== null && o.current_value !== null;
  const delta = formatDelta(o.delta);
  return (
    <Card data-testid="growth-observation" data-metric={`${type}:${o.metric_key}`} data-status={o.status}>
      <CardHeader>
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <CardTitle>
            <h3>{name}</h3>
          </CardTitle>
          <span className="rounded-full border px-2 py-0.5 text-xs font-medium">{growthStatusLabel(o.status)}</span>
        </div>
        {o.previous_state !== o.current_state || !measured ? (
          <CardDescription>
            {stateLabel(type, o.previous_state)} → {stateLabel(type, o.current_state)}
          </CardDescription>
        ) : null}
      </CardHeader>
      <CardContent className="grid gap-2 text-sm">
        {measured ? (
          <>
            <div className="grid grid-cols-[5rem_1fr_3.5rem] items-center gap-2">
              <span className="text-muted-foreground">Previous</span>
              <ScoreBar value={o.previous_value} label={`${name}, previous`} />
              <span className="text-right tabular-nums">{formatScore(o.previous_value)}</span>
            </div>
            <div className="grid grid-cols-[5rem_1fr_3.5rem] items-center gap-2">
              <span className="text-muted-foreground">Latest</span>
              <ScoreBar value={o.current_value} label={`${name}, latest`} />
              <span className="text-right tabular-nums">{formatScore(o.current_value)}</span>
            </div>
            <p data-testid="growth-delta">
              Difference: <span className="tabular-nums">{delta}</span> points of 100
              {type === "SKILL_GAP" ? " (gap size)" : ""}
            </p>
          </>
        ) : (
          <p className="text-muted-foreground" data-testid="growth-no-delta">
            No values compared: a value that is missing, unsupported or unavailable is never treated as zero.
          </p>
        )}
        {type === "COMPETENCY" && o.level_change !== null ? (
          <p>
            Level: {levelLabel(o.previous_level) ?? "—"} → {levelLabel(o.current_level) ?? "—"}
            {o.level_change === "SAME" ? " (unchanged)" : o.level_change === "UP" ? " (rose)" : " (fell)"}
          </p>
        ) : null}
        {o.previous_evidence_quality !== null || o.current_evidence_quality !== null ? (
          <p className="text-muted-foreground text-xs">
            Evidence quality: {formatPercent(o.previous_evidence_quality) ?? "—"} → {formatPercent(o.current_evidence_quality) ?? "—"}
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}

/**
 * A line only for three or more compared assessments in one unbroken chain,
 * on the full 0–100 scale. Two assessments are already shown as before/after.
 */
function Trend({ series }: { series: GrowthSeries[] }) {
  const lines = series.filter((s) => s.points.length >= 3 && s.points.every((p) => formatScore(p.value) !== null));
  if (lines.length === 0) return null;
  return (
    <section aria-labelledby="growth-trend" className="grid gap-3" data-testid="growth-trend">
      <h2 id="growth-trend" className="text-lg font-semibold">
        Trend across comparable assessments
      </h2>
      <p className="text-muted-foreground text-sm">Values from 0 to 100, oldest on the left, measured with the same versions.</p>
      <div className="grid gap-3 sm:grid-cols-2">
        {lines.map((s) => (
          <Sparkline key={`${s.metric_type}:${s.metric_key}`} series={s} />
        ))}
      </div>
    </section>
  );
}

function Sparkline({ series }: { series: GrowthSeries }) {
  const values = series.points.map((p) => Number(formatScore(p.value)));
  const width = 200;
  const height = 48;
  const step = width / (values.length - 1);
  const points = values.map((v, i) => `${(i * step).toFixed(1)},${(height - (v / 100) * height).toFixed(1)}`).join(" ");
  const name = `${metricTypeLabel(series.metric_type)}: ${metricName(series.metric_type, series.metric_key)}`;
  return (
    <figure className="grid gap-1 rounded-lg border p-3" data-testid="growth-sparkline">
      <figcaption className="text-sm font-medium">{name}</figcaption>
      <svg viewBox={`0 0 ${width} ${height}`} className="h-12 w-full" role="img" aria-label={`${name}: ${values.map((v) => v.toFixed(2)).join(", ")} of 100`}>
        <rect x="0" y="0" width={width} height={height} className="fill-muted" />
        <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2" />
      </svg>
    </figure>
  );
}

/** Context only, kept apart from growth. Never causal. */
function Activity({ snapshot }: { snapshot: GrowthSnapshot }) {
  return (
    <section aria-labelledby="growth-activity" className="grid gap-2 rounded-lg border border-dashed p-4" data-testid="growth-activity">
      <h2 id="growth-activity" className="text-base font-semibold">
        Learning activity (context only)
      </h2>
      {snapshot.activity === null ? (
        <p className="text-muted-foreground text-sm">
          There is no earlier assessment, so there is no period to show activity for. After the next code assessment, learning activity in
          between is listed here.
        </p>
      ) : (
        <p className="text-sm">
          Between these two assessments: {snapshot.activity.roadmap_steps_completed} learning step
          {snapshot.activity.roadmap_steps_completed === 1 ? "" : "s"} completed and {snapshot.activity.challenges_passed} challenge
          {snapshot.activity.challenges_passed === 1 ? "" : "s"} passed.
        </p>
      )}
      <p className="text-muted-foreground text-xs">
        Learning activity is not growth evidence and does not change any value on this page. Only a new code analysis can show change.
      </p>
    </section>
  );
}

function Timeline({ timeline, projectId, selectedId }: { timeline: GrowthSnapshotSummary[]; projectId: string; selectedId: string | null }) {
  if (timeline.length === 0) return null;
  return (
    <section aria-labelledby="growth-timeline" className="grid gap-3" data-testid="growth-timeline">
      <h2 id="growth-timeline" className="text-lg font-semibold">
        Timeline
      </h2>
      <ol className="grid gap-2">
        {timeline.map((item) => (
          <li key={item.id} className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border p-3 text-sm" data-testid="growth-timeline-item">
            <span>
              <Link href={`/app/projects/${projectId}/growth?snapshot=${item.id}`} className="underline underline-offset-4" aria-current={item.id === selectedId ? "page" : undefined}>
                {formatDateTime(item.assessed_at)}
              </Link>
              {item.id === selectedId ? <span className="text-muted-foreground"> (shown)</span> : null}
            </span>
            <span className="text-muted-foreground">{timelineLabel(item)}</span>
          </li>
        ))}
      </ol>
    </section>
  );
}

function timelineLabel(item: GrowthSnapshotSummary): string {
  if (item.status === "NOT_ESTABLISHED") return "Baseline not established";
  if (item.status === "INCOMPARABLE") return "No comparable assessment";
  const improved = TYPES.reduce((sum, type) => sum + (item.summary.statuses[type]?.IMPROVED ?? 0), 0);
  const regressed = TYPES.reduce((sum, type) => sum + (item.summary.statuses[type]?.REGRESSED ?? 0), 0);
  if (improved === 0 && regressed === 0) return "No meaningful changes detected";
  return `${improved} improved · ${regressed} regressed`;
}

function Provenance({ snapshot }: { snapshot: GrowthSnapshot }) {
  const fields = Object.keys(snapshot.versions);
  return (
    <details className="rounded-lg border p-4 text-sm" data-testid="growth-provenance">
      <summary className="cursor-pointer font-medium">Provenance</summary>
      <div className="mt-3 grid gap-3">
        <p>
          Growth rules {snapshot.rules.version}
          {snapshot.rules.current ? "" : " (earlier rules than the server now uses)"} · fingerprint{" "}
          <code className="break-all text-xs">{snapshot.rules.fingerprint}</code>
        </p>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b">
                <th scope="col" className="py-1 pr-3 font-medium">
                  Version
                </th>
                <th scope="col" className="py-1 pr-3 font-medium">
                  Previous
                </th>
                <th scope="col" className="py-1 pr-3 font-medium">
                  Latest
                </th>
              </tr>
            </thead>
            <tbody>
              {fields.map((field) => (
                <tr key={field} className="border-b last:border-0">
                  <th scope="row" className="py-1 pr-3 font-normal">
                    {versionFieldLabel(field)}
                  </th>
                  <td className="py-1 pr-3 break-all">{snapshot.previous_versions?.[field] ?? "—"}</td>
                  <td className="py-1 pr-3 break-all">{snapshot.versions[field] ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
          <dt className="text-muted-foreground">Latest skill gap analysis</dt>
          <dd className="break-all">{snapshot.current.skill_gap_snapshot_id}</dd>
          <dt className="text-muted-foreground">Latest analysis run</dt>
          <dd className="break-all">{snapshot.current.analysis_run_id}</dd>
          <dt className="text-muted-foreground">Previous skill gap analysis</dt>
          <dd className="break-all">{snapshot.previous?.skill_gap_snapshot_id ?? "—"}</dd>
          <dt className="text-muted-foreground">Previous analysis run</dt>
          <dd className="break-all">{snapshot.previous?.analysis_run_id ?? "—"}</dd>
        </dl>
      </div>
    </details>
  );
}
