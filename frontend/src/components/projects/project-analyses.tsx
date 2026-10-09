"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { isApiError } from "@/lib/api/errors";
import type { AnalysisRun, AnalysisRunStatus, SourceSnapshot } from "@/lib/api/types";
import { usePageVisible } from "@/lib/polling/use-page-visible";
import { listAnalyses, startAnalysis } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";

/** How often queued or running analyses are re-read (paused while the tab is hidden). */
export const ANALYSIS_POLL_MS = 3000;

const RECENT = 5;

const STATUS_LABELS: Record<AnalysisRunStatus, string> = {
  QUEUED: "Queued",
  RUNNING: "Running",
  SUCCEEDED: "Completed",
  FAILED: "Failed",
  CANCELLED: "Cancelled",
};

const pending = (run: AnalysisRun) => run.status === "QUEUED" || run.status === "RUNNING";

/**
 * Starts the static analysis of the newest source snapshot and follows the
 * project's recent analyses (Phase 30). The analysis itself, its scoring and
 * every assessment derived from it run on the server; this card only asks
 * for one and shows the server's status.
 */
export function ProjectAnalyses({
  projectId,
  newest,
  versions,
  canAnalyze,
  onCompleted,
}: {
  projectId: string;
  /** The newest source snapshot, or null when there is none. */
  newest: SourceSnapshot | null;
  /** Snapshot ID → version, for the snapshots the page has loaded. */
  versions: Map<string, number>;
  canAnalyze: boolean;
  /** Called when a run the card was following reaches SUCCEEDED. */
  onCompleted: () => void;
}) {
  const router = useRouter();
  const [runs, setRuns] = useState<AnalysisRun[] | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [requestError, setRequestError] = useState<unknown>(null);
  const [sending, setSending] = useState(false);
  const followed = useRef(new Set<string>());

  const authFailure = useCallback(
    (error: unknown): boolean => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return true;
      }
      return false;
    },
    [router],
  );

  const load = useCallback(() => {
    listAnalyses(projectId, 1, RECENT)
      .then((page) => {
        setLoadError(null);
        setRuns(page.data);
        let completed = false;
        for (const run of page.data) {
          if (followed.current.has(run.id) && !pending(run)) {
            followed.current.delete(run.id);
            completed ||= run.status === "SUCCEEDED";
          }
        }
        if (completed) onCompleted();
      })
      .catch((error: unknown) => {
        if (!authFailure(error)) setLoadError(error);
      });
  }, [projectId, authFailure, onCompleted]);

  useEffect(() => load(), [load]);

  const busy = runs?.some(pending) ?? false;
  const visible = usePageVisible();
  useEffect(() => {
    if (!busy || !visible) return;
    const timer = setTimeout(load, ANALYSIS_POLL_MS);
    return () => clearTimeout(timer);
  }, [busy, visible, runs, load]);

  const analyze = useCallback(() => {
    if (newest === null) return;
    setSending(true);
    setRequestError(null);
    startAnalysis(projectId, newest.id)
      .then((run) => {
        if (pending(run)) followed.current.add(run.id);
        setRuns((current) => [run, ...(current ?? []).filter((r) => r.id !== run.id)].slice(0, RECENT));
      })
      .catch((error: unknown) => {
        if (!authFailure(error)) setRequestError(error);
      })
      .finally(() => setSending(false));
  }, [projectId, newest, authFailure]);

  const newestRun = newest === null ? undefined : runs?.find((run) => run.source_snapshot_id === newest.id && run.result_type === "static_analysis");
  const alreadyAnalyzed = newestRun !== undefined && newestRun.status !== "FAILED" && newestRun.status !== "CANCELLED";

  return (
    <Card data-testid="project-analyses">
      <CardHeader>
        <CardTitle>
          <h2>Analyses</h2>
        </CardTitle>
        <CardDescription>
          A static analysis reads the source without running it. When it completes, CodeDNA, competencies and skill gaps are calculated from it,
          and a new analysis is compared with the previous one in Growth.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {newest === null ? (
          <p className="text-muted-foreground text-sm">Add source first: upload an archive or import a repository.</p>
        ) : canAnalyze ? (
          <div className="flex flex-wrap items-center gap-3">
            <Button onClick={analyze} disabled={sending || alreadyAnalyzed} data-testid="analyze-newest">
              {sending ? "Starting…" : `Analyze snapshot v${newest.version}`}
            </Button>
            {alreadyAnalyzed ? (
              <span className="text-muted-foreground text-sm" data-testid="analyze-newest-note">
                The newest snapshot already has an analysis. Add new source to assess it again.
              </span>
            ) : null}
          </div>
        ) : (
          <p className="text-muted-foreground text-sm">This project is archived: its analyses stay readable, and no new analysis can be started.</p>
        )}
        {requestError ? <ApiErrorAlert error={requestError} /> : null}
        {loadError ? <ApiErrorAlert error={loadError} /> : null}

        {runs === null && loadError === null ? (
          <p role="status" className="text-muted-foreground text-sm">
            Loading analyses…
          </p>
        ) : null}
        {runs !== null && runs.length === 0 ? <p className="text-muted-foreground text-sm">No analyses yet.</p> : null}
        {runs !== null && runs.length > 0 ? (
          <ul className="grid gap-2" aria-label="Recent analyses" data-testid="analysis-runs">
            {runs.map((run) => (
              <li key={run.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-sm" data-testid={`analysis-${run.status.toLowerCase()}`}>
                <span className="font-medium">{STATUS_LABELS[run.status]}</span>
                <span className="text-muted-foreground">
                  {versions.has(run.source_snapshot_id) ? `Snapshot v${versions.get(run.source_snapshot_id)}` : "Snapshot"} ·{" "}
                  {run.result_type === "static_analysis" ? "static analysis" : "inventory only (not scored)"} · {formatDateTime(run.created_at)}
                </span>
                {pending(run) ? (
                  <span role="status" aria-live="polite" className="text-muted-foreground">
                    {run.status === "QUEUED" ? "Waiting for a worker; this updates automatically." : "Analyzing; this updates automatically."}
                  </span>
                ) : null}
                {run.status === "FAILED" && run.failure ? <span className="text-destructive">{run.failure.message}</span> : null}
                {run.status === "SUCCEEDED" && run.result_type === "static_analysis" ? (
                  <Link href={`/app/projects/${projectId}/dna`} className="underline underline-offset-4">
                    View CodeDNA →
                  </Link>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
      </CardContent>
    </Card>
  );
}
