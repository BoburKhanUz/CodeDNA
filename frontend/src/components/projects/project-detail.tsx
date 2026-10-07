"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { DnaSummaryCard } from "@/components/dna/dna-summary-card";
import { StatusBadge } from "@/components/projects/status-badge";
import { UploadSource } from "@/components/projects/upload-source";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { isApiError } from "@/lib/api/errors";
import type { Paginated, Project, SourceSnapshot } from "@/lib/api/types";
import { archiveProject, getProject, isProjectId, listSourceSnapshots } from "@/lib/projects/client";
import { formatBytes, formatDate, formatDateTime, languageLabel, sourceTypeLabel } from "@/lib/projects/format";

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "ready"; project: Project; snapshots: Paginated<SourceSnapshot> };

/** One project: details, archive, source upload and snapshot history. */
export function ProjectDetail({ projectId }: { projectId: string }) {
  const router = useRouter();
  const [state, setState] = useState<State>(() => (isProjectId(projectId) ? { status: "loading" } : { status: "not-found" }));
  const [snapshotPage, setSnapshotPage] = useState(1);

  const handleError = useCallback(
    (error: unknown) => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      if (isApiError(error) && error.status === 404) {
        setState({ status: "not-found" });
        return;
      }
      setState({ status: "error", error });
    },
    [router],
  );

  const load = useCallback(
    (page: number) => {
      if (!isProjectId(projectId)) return;
      Promise.all([getProject(projectId), listSourceSnapshots(projectId, page)])
        .then(([project, snapshots]) => setState({ status: "ready", project, snapshots }))
        .catch(handleError);
    },
    [projectId, handleError],
  );

  useEffect(() => load(snapshotPage), [load, snapshotPage]);

  if (state.status === "loading") {
    return (
      <p role="status" className="text-muted-foreground text-sm">
        Loading project…
      </p>
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
        <Button
          variant="outline"
          className="w-fit"
          onClick={() => {
            setState({ status: "loading" });
            load(snapshotPage);
          }}
        >
          Try again
        </Button>
      </div>
    );
  }

  const { project, snapshots } = state;
  const canUpload = project.status === "ACTIVE" && project.source_type === "UPLOAD";

  return (
    <div className="grid max-w-4xl gap-6">
      <div className="grid gap-2">
        <Link href="/app/projects" className="text-muted-foreground w-fit text-sm underline-offset-4 hover:underline">
          ← Projects
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{project.name}</h1>
          <StatusBadge status={project.status} />
        </div>
        {project.description ? <p className="text-muted-foreground whitespace-pre-line">{project.description}</p> : null}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Details</h2>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
            <dt className="text-muted-foreground">Slug</dt>
            <dd>{project.slug}</dd>
            <dt className="text-muted-foreground">Source</dt>
            <dd>{sourceTypeLabel(project.source_type)}</dd>
            {project.repository_url ? (
              <>
                <dt className="text-muted-foreground">Repository URL</dt>
                <dd className="break-all">{project.repository_url}</dd>
              </>
            ) : null}
            <dt className="text-muted-foreground">Language</dt>
            <dd>{languageLabel(project.language)}</dd>
            <dt className="text-muted-foreground">Default branch</dt>
            <dd>{project.default_branch ?? "—"}</dd>
            <dt className="text-muted-foreground">Created</dt>
            <dd>{formatDate(project.created_at)}</dd>
          </dl>
        </CardContent>
      </Card>

      <DnaSummaryCard projectId={project.id} />

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Source</h2>
          </CardTitle>
          <CardDescription>
            Each upload or GitHub import becomes an immutable, versioned source snapshot.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6">
          {canUpload ? (
            <UploadSource
              projectId={project.id}
              onUploaded={() => {
                if (snapshotPage === 1) load(1);
                else setSnapshotPage(1);
              }}
            />
          ) : project.status === "ARCHIVED" ? (
            <p className="text-muted-foreground text-sm">This project is archived. It keeps its history but accepts no new source.</p>
          ) : (
            <p className="text-muted-foreground text-sm">This is a repository project: it receives source by importing from GitHub.</p>
          )}
          {project.status === "ACTIVE" || project.source_type === "REPOSITORY" ? (
            <p className="text-sm" data-testid="github-link">
              <Link href={`/app/projects/${project.id}/github`} className="font-medium underline underline-offset-4">
                {project.status === "ACTIVE" ? "Import from GitHub →" : "GitHub connection →"}
              </Link>
            </p>
          ) : null}

          <SnapshotTable snapshots={snapshots} onPage={(page) => setSnapshotPage(page)} />
        </CardContent>
      </Card>

      {project.status === "ACTIVE" ? (
        <ArchiveProject project={project} onArchived={(archived) => setState({ ...state, project: archived })} />
      ) : null}
    </div>
  );
}

function SnapshotTable({ snapshots, onPage }: { snapshots: Paginated<SourceSnapshot>; onPage: (page: number) => void }) {
  const { data, meta } = snapshots;
  if (meta.total === 0) {
    return <p className="text-muted-foreground text-sm">No source snapshots yet.</p>;
  }

  return (
    <div className="grid gap-3">
      <h3 className="text-sm font-medium">Source snapshots</h3>
      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full text-left text-sm">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-2 font-medium">Version</th>
              <th scope="col" className="px-4 py-2 font-medium">Created</th>
              <th scope="col" className="px-4 py-2 font-medium">Files</th>
              <th scope="col" className="px-4 py-2 font-medium">Size</th>
              <th scope="col" className="px-4 py-2 font-medium">Language</th>
              <th scope="col" className="px-4 py-2 font-medium">SHA-256</th>
            </tr>
          </thead>
          <tbody>
            {data.map((snapshot) => (
              <tr key={snapshot.id} className="border-t">
                <td className="px-4 py-2 font-medium">v{snapshot.version}</td>
                <td className="px-4 py-2 whitespace-nowrap">{formatDateTime(snapshot.created_at)}</td>
                <td className="px-4 py-2">{snapshot.file_count}</td>
                <td className="px-4 py-2 whitespace-nowrap">{formatBytes(snapshot.size_bytes)}</td>
                <td className="px-4 py-2">{languageLabel(snapshot.primary_language)}</td>
                <td className="px-4 py-2">
                  <code className="font-mono text-xs" title={snapshot.source_hash}>
                    {snapshot.source_hash.slice(0, 12)}
                  </code>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta.last_page > 1 ? (
        <nav aria-label="Snapshot pages" className="flex items-center gap-3 text-sm">
          <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
            Newer
          </Button>
          <span>
            Page {meta.current_page} of {meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => onPage(meta.current_page + 1)}
          >
            Older
          </Button>
        </nav>
      ) : null}
    </div>
  );
}

/** Two-step, irreversible archive. */
function ArchiveProject({ project, onArchived }: { project: Project; onArchived: (project: Project) => void }) {
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function archive() {
    setPending(true);
    setError(null);
    try {
      onArchived(await archiveProject(project.id));
    } catch (failure) {
      if (isApiError(failure) && failure.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      setError(failure);
      setPending(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Archive project</h2>
        </CardTitle>
        <CardDescription>
          An archived project keeps its source snapshots but can no longer be edited or receive new source. This cannot be undone.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {error ? <ApiErrorAlert error={error} /> : null}
        {confirming ? (
          <div className="flex flex-wrap gap-2">
            <Button variant="destructive" onClick={archive} disabled={pending}>
              {pending ? "Archiving…" : "Yes, archive this project"}
            </Button>
            <Button variant="outline" onClick={() => setConfirming(false)} disabled={pending}>
              Cancel
            </Button>
          </div>
        ) : (
          <Button variant="outline" className="w-fit" onClick={() => setConfirming(true)}>
            Archive project
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
