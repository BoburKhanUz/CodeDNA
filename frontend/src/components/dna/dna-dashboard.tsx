"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { DnaReport } from "@/components/dna/dna-report";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { AnalysisRun, DnaSnapshot, DnaSnapshotSummary, Paginated, Project } from "@/lib/api/types";
import { getDnaSnapshot, isDnaSnapshotId, listDnaSnapshots } from "@/lib/dna/client";
import { formatPercent, formatScore } from "@/lib/dna/format";
import { getProject, isProjectId, listAnalyses } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";

type State =
  | { status: "loading" }
  | { status: "not-found"; what: "project" | "assessment" }
  | { status: "error"; error: unknown }
  | { status: "empty"; project: Project; latestStaticRun: AnalysisRun | null }
  | { status: "ready"; project: Project; history: Paginated<DnaSnapshotSummary>; snapshot: DnaSnapshot };

/**
 * The CodeDNA dashboard of one project (Phase 12): the newest DNA snapshot,
 * or the one in the URL, plus the project's assessment history. Read-only;
 * it renders the API's values and computes none.
 */
export function DnaDashboard({ projectId, snapshotId }: { projectId: string; snapshotId?: string }) {
  const router = useRouter();
  const validIds = isProjectId(projectId) && (snapshotId === undefined || isDnaSnapshotId(snapshotId));
  const [state, setState] = useState<State>(() =>
    validIds ? { status: "loading" } : { status: "not-found", what: isProjectId(projectId) ? "assessment" : "project" },
  );

  const handleError = useCallback(
    (error: unknown) => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      if (isApiError(error) && error.status === 404) {
        setState({ status: "not-found", what: "project" });
        return;
      }
      setState({ status: "error", error });
    },
    [router],
  );

  const load = useCallback(() => {
    if (!validIds) return;
    fetchDashboard(projectId, snapshotId).then(setState).catch(handleError);
  }, [projectId, snapshotId, validIds, handleError]);

  useEffect(() => load(), [load]);

  if (state.status === "loading") {
    return <DashboardSkeleton />;
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{state.what === "project" ? "Project not found" : "Assessment not found"}</h1>
        <p className="text-muted-foreground">It does not exist, or it belongs to another account.</p>
        <Link href={state.what === "project" ? "/app/projects" : `/app/projects/${projectId}/dna`} className="underline underline-offset-4">
          {state.what === "project" ? "Back to projects" : "Back to CodeDNA"}
        </Link>
      </div>
    );
  }

  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <ApiErrorAlert error={state.error} />
        <Button
          variant="outline"
          className="w-fit"
          onClick={() => {
            setState({ status: "loading" });
            load();
          }}
        >
          Try again
        </Button>
      </div>
    );
  }

  const { project } = state;

  return (
    <div className="grid max-w-6xl gap-6">
      <Header project={project} snapshot={state.status === "ready" ? state.snapshot : null} />
      {state.status === "empty" ? (
        <EmptyState run={state.latestStaticRun} projectId={project.id} />
      ) : (
        <>
          <DnaReport snapshot={state.snapshot} projectId={project.id} />
          <Card data-testid="competency-link">
            <CardHeader>
              <CardTitle>
                <h2>Competency Matrix</h2>
              </CardTitle>
              <CardDescription>
                Engineering competencies supported by this evidence: complexity management, function design, type structure
                and code hygiene, each with its level and the evidence behind it.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Link href={`/app/projects/${project.id}/competencies`} className="text-sm font-medium underline underline-offset-4">
                View Competency Matrix →
              </Link>
            </CardContent>
          </Card>
          <Card data-testid="growth-link">
            <CardHeader>
              <CardTitle>
                <h2>Growth</h2>
              </CardTitle>
              <CardDescription>
                How this assessment compares with the one immediately before it, measured with the same versions. Only new code analysis
                shows change.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Link href={`/app/projects/${project.id}/growth`} className="text-sm font-medium underline underline-offset-4">
                View Growth →
              </Link>
            </CardContent>
          </Card>
          <History history={state.history} projectId={project.id} selectedId={state.snapshot.id} />
        </>
      )}
      <p className="text-muted-foreground max-w-3xl text-xs">
        CodeDNA measures deterministic characteristics of the analyzed source code. It does not infer developer seniority,
        intelligence, ability, personality, or professional level.
      </p>
    </div>
  );
}

/** Loads everything the dashboard shows; API errors propagate, except a missing assessment. */
async function fetchDashboard(projectId: string, snapshotId: string | undefined): Promise<State> {
  const [project, history] = await Promise.all([getProject(projectId), listDnaSnapshots(projectId)]);
  const selectedId = snapshotId ?? history.data[0]?.id;
  if (selectedId === undefined) {
    // No assessment yet: report the newest static analysis as the API states it, nothing more.
    const runs = await listAnalyses(projectId);
    return { status: "empty", project, latestStaticRun: runs.data.find((run) => run.result_type === "static_analysis") ?? null };
  }
  try {
    return { status: "ready", project, history, snapshot: await getDnaSnapshot(projectId, selectedId) };
  } catch (error) {
    if (isApiError(error) && error.status === 404) {
      return { status: "not-found", what: "assessment" };
    }
    throw error;
  }
}

function Header({ project, snapshot }: { project: Project; snapshot: DnaSnapshot | null }) {
  return (
    <div className="grid gap-2">
      <Link href={`/app/projects/${project.id}`} className="text-muted-foreground w-fit text-sm underline-offset-4 hover:underline">
        ← {project.name}
      </Link>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">CodeDNA</h1>
        <StatusBadge status={project.status} />
      </div>
      <p className="text-muted-foreground">
        {project.name}: deterministic analysis of the analyzed source code.
      </p>
      {snapshot ? (
        <p className="text-muted-foreground text-sm">
          Scoring version {snapshot.scoring_version} · calculated {formatDateTime(snapshot.created_at)}
          {snapshot.source_snapshot ? ` · source snapshot v${snapshot.source_snapshot.version}` : ""}
        </p>
      ) : null}
    </div>
  );
}

function EmptyState({ run, projectId }: { run: AnalysisRun | null; projectId: string }) {
  let title = "No CodeDNA assessment is available yet.";
  let detail =
    "The project needs a completed static analysis of a source snapshot; the assessment is created from it. Static analyses are started through the API; there is no analysis screen yet.";
  if (run?.status === "QUEUED" || run?.status === "RUNNING") {
    title = "A static analysis is in progress.";
    detail = `The newest static analysis is ${run.status.toLowerCase()} (started ${formatDateTime(run.created_at)}). The assessment appears here once it has completed and been scored.`;
  } else if (run?.status === "SUCCEEDED") {
    title = "The static analysis has completed, but no assessment is available yet.";
    detail = "The analysis result is stored and scoring has not produced an assessment for it yet. Check again later.";
  } else if (run?.status === "FAILED" || run?.status === "CANCELLED") {
    title = "No CodeDNA assessment is available yet.";
    detail = `The newest static analysis ended as ${run.status.toLowerCase()}${run.failure ? `: ${run.failure.message}` : "."} A completed static analysis is needed.`;
  }

  return (
    <Card data-testid="dna-empty">
      <CardHeader>
        <CardTitle>
          <h2>{title}</h2>
        </CardTitle>
        <CardDescription>{detail}</CardDescription>
      </CardHeader>
      <CardContent>
        <Link href={`/app/projects/${projectId}`} className="text-sm underline underline-offset-4">
          Back to the project and its source snapshots
        </Link>
      </CardContent>
    </Card>
  );
}

function History({ history, projectId, selectedId }: { history: Paginated<DnaSnapshotSummary>; projectId: string; selectedId: string }) {
  if (history.meta.total <= 1) return null;

  return (
    <section aria-labelledby="dna-history" className="grid gap-3">
      <h2 id="dna-history" className="text-lg font-semibold tracking-tight">
        Assessments
      </h2>
      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full text-left text-sm">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-2 font-medium">Calculated</th>
              <th scope="col" className="px-4 py-2 font-medium">Source</th>
              <th scope="col" className="px-4 py-2 font-medium">Score</th>
              <th scope="col" className="px-4 py-2 font-medium">Data quality</th>
              <th scope="col" className="px-4 py-2 font-medium">Scoring version</th>
            </tr>
          </thead>
          <tbody>
            {history.data.map((item) => (
              <tr key={item.id} className="border-t" aria-current={item.id === selectedId ? "true" : undefined}>
                <td className="px-4 py-2 whitespace-nowrap">
                  {item.id === selectedId ? (
                    <span className="font-medium">{formatDateTime(item.created_at)} (shown)</span>
                  ) : (
                    <Link href={`/app/projects/${projectId}/dna/${item.id}`} className="underline underline-offset-4">
                      {formatDateTime(item.created_at)}
                    </Link>
                  )}
                </td>
                <td className="px-4 py-2">{item.source_snapshot_version === null ? "—" : `v${item.source_snapshot_version}`}</td>
                <td className="px-4 py-2 tabular-nums">{item.status === "READY" ? formatScore(item.overall_score) : "Insufficient data"}</td>
                <td className="px-4 py-2 tabular-nums">{formatPercent(item.data_quality) ?? "—"}</td>
                <td className="px-4 py-2">{item.scoring_version}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {history.meta.total > history.data.length ? (
        <p className="text-muted-foreground text-xs">
          Showing the {history.data.length} newest of {history.meta.total} assessments.
        </p>
      ) : null}
    </section>
  );
}

function DashboardSkeleton() {
  return (
    <div className="grid max-w-6xl gap-6" role="status" aria-label="Loading CodeDNA">
      <div className="grid gap-2">
        <Skeleton className="h-4 w-32" />
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-4 w-80" />
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <Skeleton className="h-48" />
        <Skeleton className="h-48" />
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <Skeleton className="h-64" />
        <Skeleton className="h-64" />
        <Skeleton className="h-64" />
      </div>
      <span className="sr-only">Loading CodeDNA…</span>
    </div>
  );
}
