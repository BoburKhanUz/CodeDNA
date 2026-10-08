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
import type { GitHubBranch, GitHubConnection, GitHubImport, GitHubInstallation, GitHubRepository, Project, ProjectGitHub } from "@/lib/api/types";
import {
  changeGitHubBranch,
  connectGitHubRepository,
  disconnectGitHub,
  getProjectGitHub,
  listGitHubBranches,
  listGitHubImports,
  listGitHubInstallations,
  listGitHubRepositories,
  requestGitHubImport,
  startGitHubAuthorization,
} from "@/lib/github/client";
import { importFailureMessage, importStatusLabel, isSafeRedirect, shortSha } from "@/lib/github/format";
import { usePageVisible } from "@/lib/polling/use-page-visible";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";

export const NOTICE =
  "GitHub is only a source: each import becomes an immutable source snapshot, analyzed like an upload. Repository code is never executed, and disconnecting never deletes imported snapshots or analyses.";

export const POLL_MS = 3000;

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "ready"; project: Project; github: ProjectGitHub; imports: GitHubImport[] };

/**
 * A project's GitHub source (Phase 19): connect a repository the user can
 * reach through the CodeDNA GitHub App, choose a branch, and import its
 * current commit as a source snapshot. Nothing here ever holds a GitHub
 * token; every repository, branch and commit is verified by the server.
 */
export function ProjectGitHubView({ projectId, notice }: { projectId: string; notice?: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const [actionError, setActionError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

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

  // Only the most recently started load may update the page, so a slow poll never overwrites newer state.
  const loads = useRef(0);
  const load = useCallback((): Promise<void> => {
    if (!valid) return Promise.resolve();
    const sequence = ++loads.current;
    return Promise.all([getProject(projectId), getProjectGitHub(projectId), listGitHubImports(projectId, 1, 10)])
      .then(([project, github, imports]) => {
        if (sequence === loads.current) setState({ status: "ready", project, github, imports: imports.data });
      })
      .catch((error: unknown) => {
        if (sequence === loads.current) handleError(error);
      });
  }, [projectId, valid, handleError]);

  useEffect(() => {
    void load();
  }, [load]);

  // Follow an import in progress until it finishes: only the connection (its
  // latest import) is polled; the whole page reloads once when it is done
  // (Phase 26: one request per tick instead of three). Paused while the tab is hidden.
  const importing = state.status === "ready" && (state.github.latest_import?.status === "QUEUED" || state.github.latest_import?.status === "RUNNING");
  const visible = usePageVisible();
  useEffect(() => {
    if (!importing || !visible) return;
    const timer = window.setTimeout(() => {
      const sequence = loads.current;
      getProjectGitHub(projectId)
        .then((github) => {
          if (sequence !== loads.current) return;
          const status = github.latest_import?.status;
          if (status === "QUEUED" || status === "RUNNING") {
            setState((current) => (current.status === "ready" ? { ...current, github } : current));
          } else {
            void load();
          }
        })
        .catch((error: unknown) => {
          if (sequence === loads.current) handleError(error);
        });
    }, POLL_MS);
    return () => window.clearTimeout(timer);
  }, [importing, visible, load, state, projectId, handleError]);

  const act = useCallback(
    async (action: () => Promise<unknown>) => {
      setBusy(true);
      setActionError(null);
      try {
        await action();
        // Stay busy until the page shows the result, so a second click cannot repeat the action.
        await load();
      } catch (error) {
        if (isApiError(error) && error.status === 401) {
          handleError(error);
          return;
        }
        setActionError(error);
      } finally {
        setBusy(false);
      }
    },
    [load, handleError],
  );

  const authorize = useCallback(
    async (kind: "authorize_url" | "install_url") => {
      setBusy(true);
      setActionError(null);
      try {
        const start = await startGitHubAuthorization(projectId);
        const target = start[kind];
        if (!isSafeRedirect(target)) throw new Error("Unexpected authorization URL.");
        window.location.assign(target);
      } catch (error) {
        setActionError(error);
        setBusy(false);
      }
    },
    [projectId],
  );

  if (state.status === "loading") {
    return (
      <div className="grid max-w-4xl gap-6" role="status" aria-label="Loading GitHub connection">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-40" />
        <span className="sr-only">Loading GitHub connection…</span>
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

  const { project, github, imports } = state;
  const archived = project.status === "ARCHIVED";
  const connection = github.connection;

  return (
    <div className="grid max-w-4xl gap-6">
      <div className="grid gap-2">
        <Link href={`/app/projects/${project.id}`} className="text-muted-foreground w-fit text-sm underline-offset-4 hover:underline">
          ← {project.name}
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">GitHub</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl text-sm" data-testid="github-notice">
          {NOTICE}
        </p>
        {notice ? (
          <p className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950" role="status" data-testid="github-callback-notice">
            {notice}
          </p>
        ) : null}
        {archived ? (
          <p className="text-muted-foreground text-sm" data-testid="github-archived">
            This project is archived: its imports stay readable, but it cannot connect, change branch or import. It can still be disconnected.
          </p>
        ) : null}
      </div>

      {actionError !== null ? <ApiErrorAlert error={actionError} /> : null}

      {!github.configured ? (
        <Card data-testid="github-not-configured">
          <CardHeader>
            <CardTitle>
              <h2>GitHub is not available</h2>
            </CardTitle>
            <CardDescription>GitHub integration is not configured on this server. Source can still be uploaded as a ZIP archive.</CardDescription>
          </CardHeader>
        </Card>
      ) : connection !== null ? (
        <Connected
          project={project}
          connection={connection}
          latest={github.latest_import}
          archived={archived}
          busy={busy}
          accountConnected={github.account_connected}
          onImport={() => act(() => requestGitHubImport(project.id))}
          onBranch={(branch) => act(() => changeGitHubBranch(project.id, branch))}
          onDisconnect={() => act(() => disconnectGitHub(project.id))}
          onReauthorize={() => authorize("authorize_url")}
        />
      ) : !github.account_connected ? (
        <Card data-testid="github-not-connected">
          <CardHeader>
            <CardTitle>
              <h2>Not connected</h2>
            </CardTitle>
            <CardDescription>
              Connect your GitHub account to choose a repository. CodeDNA asks only for read access to repository contents and metadata.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-3">
            <Button disabled={busy || archived} onClick={() => authorize("authorize_url")} data-testid="github-connect">
              {busy ? "Connecting…" : "Connect GitHub"}
            </Button>
          </CardContent>
        </Card>
      ) : archived ? (
        <Card data-testid="github-not-connected">
          <CardHeader>
            <CardTitle>
              <h2>Not connected</h2>
            </CardTitle>
            <CardDescription>This archived project has no GitHub connection.</CardDescription>
          </CardHeader>
        </Card>
      ) : (
        <RepositoryPicker
          busy={busy}
          onInstall={() => authorize("install_url")}
          onConnect={(repository) => act(() => connectGitHubRepository(project.id, repository.id))}
        />
      )}

      <ImportHistory projectId={project.id} imports={imports} />
    </div>
  );
}

function Connected({
  project,
  connection,
  latest,
  archived,
  busy,
  accountConnected,
  onImport,
  onBranch,
  onDisconnect,
  onReauthorize,
}: {
  project: Project;
  connection: GitHubConnection;
  latest: GitHubImport | null;
  archived: boolean;
  busy: boolean;
  accountConnected: boolean;
  onImport: () => void;
  onBranch: (branch: string) => void;
  onDisconnect: () => void;
  onReauthorize: () => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const inProgress = latest?.status === "QUEUED" || latest?.status === "RUNNING";
  const { repository } = connection;

  return (
    <>
      <Card data-testid="github-connection">
        <CardHeader>
          <CardTitle>
            <h2>Connected repository</h2>
          </CardTitle>
          <CardDescription>Repository details as last verified with GitHub on {formatDateTime(connection.metadata_verified_at)}.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4">
          <dl className="grid gap-2 text-sm sm:grid-cols-[11rem_1fr]">
            <dt className="text-muted-foreground">Repository</dt>
            <dd className="font-medium" data-testid="github-repository">
              {repository.full_name}
            </dd>
            <dt className="text-muted-foreground">Visibility</dt>
            <dd>{repository.private ? "Private" : "Public"}</dd>
            {repository.archived ? (
              <>
                <dt className="text-muted-foreground">On GitHub</dt>
                <dd data-testid="github-repository-archived">Archived (read-only on GitHub; imports still work)</dd>
              </>
            ) : null}
            <dt className="text-muted-foreground">Branch</dt>
            <dd data-testid="github-branch">
              {connection.branch}
              {connection.branch === repository.default_branch ? <span className="text-muted-foreground"> (default)</span> : null}
            </dd>
            <dt className="text-muted-foreground">Last imported commit</dt>
            <dd>
              <code className="font-mono text-xs" title={connection.last_imported_commit_sha ?? undefined} data-testid="github-last-commit">
                {shortSha(connection.last_imported_commit_sha)}
              </code>
            </dd>
            <dt className="text-muted-foreground">Last import</dt>
            <dd>{connection.last_imported_at === null ? "Never" : formatDateTime(connection.last_imported_at)}</dd>
          </dl>

          <LatestImport projectId={project.id} latest={latest} />

          {!accountConnected ? (
            <div className="grid gap-2 rounded-lg border p-3 text-sm" data-testid="github-reauthorize">
              <p>Your GitHub authorization is missing or expired. Connect GitHub again to import.</p>
              <Button variant="outline" className="w-fit" disabled={busy} onClick={onReauthorize}>
                Connect GitHub
              </Button>
            </div>
          ) : null}

          {!archived ? (
            <div className="flex flex-wrap items-center gap-3">
              <Button onClick={onImport} disabled={busy || inProgress || !accountConnected} data-testid="github-import">
                {inProgress ? "Importing…" : "Import from GitHub"}
              </Button>
              <span className="text-muted-foreground text-sm">Imports the current commit of {connection.branch}.</span>
            </div>
          ) : null}
        </CardContent>
      </Card>

      {!archived && accountConnected ? <BranchPicker key={connection.branch} projectId={project.id} current={connection.branch} busy={busy || inProgress} onChoose={onBranch} /> : null}

      <Card data-testid="github-disconnect">
        <CardHeader>
          <CardTitle>
            <h2>Disconnect</h2>
          </CardTitle>
          <CardDescription>
            Ends GitHub access for this project. Every imported source snapshot, analysis, CodeDNA result and growth record stays.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-3">
          {confirming ? (
            <>
              <Button variant="destructive" disabled={busy} onClick={onDisconnect} data-testid="github-disconnect-confirm">
                Disconnect {repository.full_name}
              </Button>
              <Button variant="outline" disabled={busy} onClick={() => setConfirming(false)}>
                Keep connection
              </Button>
            </>
          ) : (
            <Button variant="outline" disabled={busy} onClick={() => setConfirming(true)} data-testid="github-disconnect-start">
              Disconnect…
            </Button>
          )}
        </CardContent>
      </Card>
    </>
  );
}

function LatestImport({ projectId, latest }: { projectId: string; latest: GitHubImport | null }) {
  if (latest === null) {
    return (
      <p className="text-muted-foreground text-sm" data-testid="github-latest-import">
        No import yet.
      </p>
    );
  }
  return (
    <div className="grid gap-1 rounded-lg border p-3 text-sm" data-testid="github-latest-import" data-status={latest.status} aria-live="polite">
      <p className="font-medium">
        Latest import: {importStatusLabel(latest.status)}
        {latest.commit_sha !== null ? <span className="text-muted-foreground font-normal"> · commit {shortSha(latest.commit_sha)}</span> : null}
      </p>
      {latest.status === "SUCCEEDED" && latest.source_snapshot !== null ? (
        <p data-testid="github-import-success">
          Source imported{latest.created_snapshot ? "" : " (this commit was already imported, so its snapshot is reused)"}. Ready for analysis:{" "}
          <Link href={`/app/projects/${projectId}`} className="underline underline-offset-4">
            source snapshot v{latest.source_snapshot.version}
          </Link>
          .
        </p>
      ) : null}
      {latest.status === "FAILED" ? (
        <p className="text-destructive" data-testid="github-import-failure">
          {importFailureMessage(latest.failure_code)}
        </p>
      ) : null}
      {latest.status === "CANCELLED" ? <p className="text-muted-foreground">Cancelled when the repository was disconnected.</p> : null}
    </div>
  );
}

function RepositoryPicker({ busy, onInstall, onConnect }: { busy: boolean; onInstall: () => void; onConnect: (repository: GitHubRepository) => void }) {
  const [installations, setInstallations] = useState<GitHubInstallation[] | null>(null);
  const [installation, setInstallation] = useState<number | null>(null);
  const [repositories, setRepositories] = useState<GitHubRepository[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [selected, setSelected] = useState<GitHubRepository | null>(null);

  useEffect(() => {
    listGitHubInstallations()
      .then((list) => {
        setInstallations(list);
        setInstallation(list[0]?.id ?? null);
        if (list.length === 0) setLoading(false);
      })
      .catch((e: unknown) => {
        setError(e);
        setLoading(false);
      });
  }, []);

  useEffect(() => {
    if (installation === null) return;
    listGitHubRepositories(installation, page)
      .then((result) => {
        setRepositories((current) => (page === 1 ? result.data : [...current, ...result.data]));
        setHasMore(result.meta.has_more);
        setError(null);
      })
      .catch((e: unknown) => setError(e))
      .finally(() => setLoading(false));
  }, [installation, page]);

  return (
    <Card data-testid="github-repository-picker">
      <CardHeader>
        <CardTitle>
          <h2>Choose a repository</h2>
        </CardTitle>
        <CardDescription>Repositories you can access through the CodeDNA GitHub App. The default branch is used first; you can change it after connecting.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {error !== null ? <ApiErrorAlert error={error} /> : null}
        {installations !== null && installations.length === 0 ? (
          <div className="grid gap-2 text-sm" data-testid="github-no-installation">
            <p>The CodeDNA GitHub App is not installed on any account you can access.</p>
            <Button className="w-fit" disabled={busy} onClick={onInstall}>
              Install the CodeDNA GitHub App
            </Button>
          </div>
        ) : null}
        {installations !== null && installations.length > 1 ? (
          <label className="grid w-fit gap-1 text-sm">
            <span className="text-muted-foreground">GitHub account</span>
            <select
              className="rounded-md border px-2 py-1"
              value={installation ?? ""}
              onChange={(event) => {
                setLoading(true);
                setRepositories([]);
                setPage(1);
                setSelected(null);
                setInstallation(Number(event.target.value));
              }}
            >
              {installations.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.account}
                </option>
              ))}
            </select>
          </label>
        ) : null}
        {loading && repositories.length === 0 ? <Skeleton className="h-24" /> : null}
        {!loading && installation !== null && repositories.length === 0 && error === null ? (
          <p className="text-muted-foreground text-sm" data-testid="github-no-repositories">
            No repositories are available in this installation. Grant the CodeDNA GitHub App access to a repository on GitHub, then reload.
          </p>
        ) : null}
        {repositories.length > 0 ? (
          <ul className="grid gap-2" data-testid="github-repositories">
            {repositories.map((repository) => (
              <li key={repository.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm" data-testid="github-repository-option">
                <span className="grid gap-0.5">
                  <span className="font-medium">{repository.full_name}</span>
                  <span className="text-muted-foreground text-xs">
                    {repository.private ? "Private" : "Public"} · default branch {repository.default_branch}
                    {repository.archived ? " · archived" : ""}
                  </span>
                </span>
                <Button variant={selected?.id === repository.id ? "default" : "outline"} size="sm" onClick={() => setSelected(repository)}>
                  {selected?.id === repository.id ? "Selected" : "Select"}
                </Button>
              </li>
            ))}
          </ul>
        ) : null}
        {hasMore ? (
          <Button
            variant="outline"
            className="w-fit"
            disabled={loading}
            onClick={() => {
              setLoading(true);
              setPage((p) => p + 1);
            }}
          >
            {loading ? "Loading…" : "More repositories"}
          </Button>
        ) : null}
        {selected !== null ? (
          <div className="grid gap-2 rounded-lg border p-3 text-sm" data-testid="github-connection-summary">
            <p>
              Connect <strong>{selected.full_name}</strong> ({selected.private ? "private" : "public"}) on branch <strong>{selected.default_branch}</strong>.
            </p>
            <Button className="w-fit" disabled={busy} onClick={() => onConnect(selected)} data-testid="github-connect-repository">
              {busy ? "Connecting…" : "Connect repository"}
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

function BranchPicker({ projectId, current, busy, onChoose }: { projectId: string; current: string; busy: boolean; onChoose: (branch: string) => void }) {
  const [branches, setBranches] = useState<GitHubBranch[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [choice, setChoice] = useState(current);
  const loaded = useRef(0);

  useEffect(() => {
    const request = ++loaded.current;
    listGitHubBranches(projectId, page)
      .then((result) => {
        if (request !== loaded.current) return;
        setBranches((existing) => (page === 1 ? result.data : [...existing, ...result.data]));
        setHasMore(result.meta.has_more);
        setError(null);
      })
      .catch((e: unknown) => setError(e))
      .finally(() => setLoading(false));
  }, [projectId, page]);

  return (
    <Card data-testid="github-branches">
      <CardHeader>
        <CardTitle>
          <h2>Branch</h2>
        </CardTitle>
        <CardDescription>Future imports read this branch. Past imports keep the branch and commit they came from.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {error !== null ? <ApiErrorAlert error={error} /> : null}
        {loading && branches.length === 0 ? <Skeleton className="h-10" /> : null}
        {branches.length > 0 ? (
          <div className="flex flex-wrap items-end gap-3">
            <label className="grid gap-1 text-sm">
              <span className="text-muted-foreground">Branch</span>
              <select className="rounded-md border px-2 py-1" value={choice} onChange={(event) => setChoice(event.target.value)} data-testid="github-branch-select">
                {branches.some((b) => b.name === current) ? null : <option value={current}>{current}</option>}
                {branches.map((branch) => (
                  <option key={branch.name} value={branch.name}>
                    {branch.name}
                    {branch.protected ? " (protected)" : ""}
                  </option>
                ))}
              </select>
            </label>
            <Button variant="outline" disabled={busy || choice === current} onClick={() => onChoose(choice)} data-testid="github-branch-save">
              Use this branch
            </Button>
            {hasMore ? (
              <Button
                variant="ghost"
                disabled={loading}
                onClick={() => {
                  setLoading(true);
                  setPage((p) => p + 1);
                }}
              >
                More branches
              </Button>
            ) : null}
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

function ImportHistory({ projectId, imports }: { projectId: string; imports: GitHubImport[] }) {
  if (imports.length === 0) return null;
  return (
    <section aria-labelledby="github-imports" className="grid gap-3" data-testid="github-import-history">
      <h2 id="github-imports" className="text-lg font-semibold">
        Imports
      </h2>
      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full text-left text-sm">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-2 font-medium">Requested</th>
              <th scope="col" className="px-4 py-2 font-medium">Repository</th>
              <th scope="col" className="px-4 py-2 font-medium">Branch</th>
              <th scope="col" className="px-4 py-2 font-medium">Commit</th>
              <th scope="col" className="px-4 py-2 font-medium">Result</th>
            </tr>
          </thead>
          <tbody>
            {imports.map((item) => (
              <tr key={item.id} className="border-t" data-testid="github-import-row">
                <td className="px-4 py-2 whitespace-nowrap">{formatDateTime(item.created_at)}</td>
                <td className="px-4 py-2">{item.repository}</td>
                <td className="px-4 py-2">{item.ref}</td>
                <td className="px-4 py-2">
                  <code className="font-mono text-xs">{shortSha(item.commit_sha)}</code>
                </td>
                <td className="px-4 py-2">
                  {importStatusLabel(item.status)}
                  {item.status === "SUCCEEDED" && item.source_snapshot !== null ? (
                    <>
                      {" · "}
                      <Link href={`/app/projects/${projectId}`} className="underline underline-offset-4">
                        snapshot v{item.source_snapshot.version}
                      </Link>
                      {item.created_snapshot ? "" : " (reused)"}
                    </>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
