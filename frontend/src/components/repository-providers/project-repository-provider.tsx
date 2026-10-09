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
import type {
  Project,
  ProjectRepositoryProvider,
  ProviderRepository,
  RepositoryProviderConnection,
  RepositoryProviderImport,
  RepositoryProviderKey,
} from "@/lib/api/types";
import { importStatusLabel, isSafeRedirect, shortSha } from "@/lib/github/format";
import { usePageVisible } from "@/lib/polling/use-page-visible";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";
import {
  changeProviderBranch,
  connectProviderRepository,
  disconnectProviderRepository,
  getProjectRepositoryProvider,
  listProviderBranches,
  listProviderImports,
  listProviderRepositories,
  requestProviderImport,
  startProviderAuthorization,
} from "@/lib/repository-providers/client";
import { providerFailureMessage } from "@/lib/repository-providers/format";

export const NOTICE =
  "GitLab and Bitbucket Cloud are only sources: each import becomes an immutable source snapshot, analyzed like an upload. Repository code is never executed, and disconnecting never deletes imported snapshots or analyses.";

export const POLL_MS = 3000;

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "ready"; project: Project; source: ProjectRepositoryProvider; imports: RepositoryProviderImport[] };

const active = (item: RepositoryProviderImport | null | undefined): boolean => item?.status === "QUEUED" || item?.status === "RUNNING";

/**
 * A project's GitLab or Bitbucket Cloud source (Phase 28): authorize an
 * account, choose one of its repositories, and import the connected branch's
 * current commit as a source snapshot. Nothing here holds a provider token
 * or URL; every repository, branch and commit is verified by the server.
 */
export function ProjectRepositoryProviderView({ projectId, notice }: { projectId: string; notice?: string }) {
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
    return Promise.all([getProject(projectId), getProjectRepositoryProvider(projectId), listProviderImports(projectId, 1, 10)])
      .then(([project, source, imports]) => {
        if (sequence === loads.current) setState({ status: "ready", project, source, imports: imports.data });
      })
      .catch((error: unknown) => {
        if (sequence === loads.current) handleError(error);
      });
  }, [projectId, valid, handleError]);

  useEffect(() => {
    void load();
  }, [load]);

  // Follow an import in progress: one request per tick, paused while the tab
  // is hidden; the whole page reloads once when the import is done.
  const importing = state.status === "ready" && active(state.source.latest_import);
  const visible = usePageVisible();
  useEffect(() => {
    if (!importing || !visible) return;
    const timer = window.setTimeout(() => {
      const sequence = loads.current;
      getProjectRepositoryProvider(projectId)
        .then((source) => {
          if (sequence !== loads.current) return;
          if (active(source.latest_import)) {
            setState((current) => (current.status === "ready" ? { ...current, source } : current));
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
    async (key: RepositoryProviderKey) => {
      setBusy(true);
      setActionError(null);
      try {
        const start = await startProviderAuthorization(key, projectId);
        if (!isSafeRedirect(start.authorize_url)) throw new Error("Unexpected authorization URL.");
        window.location.assign(start.authorize_url);
      } catch (error) {
        setActionError(error);
        setBusy(false);
      }
    },
    [projectId],
  );

  if (state.status === "loading") {
    return (
      <div className="grid max-w-4xl gap-6" role="status" aria-label="Loading repository connection">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-40" />
        <span className="sr-only">Loading repository connection…</span>
      </div>
    );
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">Project not found</h1>
        <p className="text-muted-foreground">It does not exist, or you do not have access to it.</p>
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

  const { project, source, imports } = state;
  const archived = project.status === "ARCHIVED";
  const connection = source.connection;
  const configured = source.providers.filter((p) => p.configured);
  const connectedProvider = connection === null ? undefined : source.providers.find((p) => p.provider === connection.provider);

  return (
    <div className="grid max-w-4xl gap-6">
      <div className="grid gap-2">
        <Link href={`/app/projects/${project.id}`} className="text-muted-foreground w-fit text-sm underline-offset-4 hover:underline">
          ← {project.name}
        </Link>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">GitLab and Bitbucket</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl text-sm" data-testid="provider-notice">
          {NOTICE}
        </p>
        {notice ? (
          <p className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950" role="status" data-testid="provider-callback-notice">
            {notice}
          </p>
        ) : null}
        {archived ? (
          <p className="text-muted-foreground text-sm" data-testid="provider-archived">
            This project is archived: its imports stay readable, but it cannot connect, change branch or import. It can still be disconnected.
          </p>
        ) : null}
      </div>

      {actionError !== null ? <ApiErrorAlert error={actionError} /> : null}

      {connection !== null && connectedProvider !== undefined ? (
        <Connected
          project={project}
          connection={connection}
          providerName={connectedProvider.name}
          latest={source.latest_import}
          archived={archived}
          busy={busy}
          accountConnected={connectedProvider.account_connected}
          onImport={() => act(() => requestProviderImport(project.id))}
          onBranch={(branch) => act(() => changeProviderBranch(project.id, branch))}
          onDisconnect={() => act(() => disconnectProviderRepository(project.id))}
          onReauthorize={() => authorize(connection.provider)}
        />
      ) : source.github_connected ? (
        <Card data-testid="provider-github-connected">
          <CardHeader>
            <CardTitle>
              <h2>GitHub is this project&apos;s source</h2>
            </CardTitle>
            <CardDescription>A project has one repository source. Disconnect GitHub first to use GitLab or Bitbucket Cloud instead.</CardDescription>
          </CardHeader>
          <CardContent>
            <Link href={`/app/projects/${project.id}/github`} className="text-sm underline underline-offset-4">
              GitHub connection →
            </Link>
          </CardContent>
        </Card>
      ) : configured.length === 0 ? (
        <Card data-testid="provider-not-configured">
          <CardHeader>
            <CardTitle>
              <h2>GitLab and Bitbucket are not available</h2>
            </CardTitle>
            <CardDescription>Neither integration is configured on this server. Source can still be uploaded as a ZIP archive.</CardDescription>
          </CardHeader>
        </Card>
      ) : archived ? (
        <Card data-testid="provider-not-connected">
          <CardHeader>
            <CardTitle>
              <h2>Not connected</h2>
            </CardTitle>
            <CardDescription>This archived project has no GitLab or Bitbucket connection.</CardDescription>
          </CardHeader>
        </Card>
      ) : (
        configured.map((item) =>
          item.account_connected ? (
            <RepositoryPicker
              key={item.provider}
              providerKey={item.provider}
              providerName={item.name}
              busy={busy}
              onConnect={(repository) => act(() => connectProviderRepository(project.id, item.provider, repository.id))}
            />
          ) : (
            <Card key={item.provider} data-testid={`provider-connect-${item.provider}`}>
              <CardHeader>
                <CardTitle>
                  <h2>{item.name}</h2>
                </CardTitle>
                <CardDescription>
                  Connect your {item.name} account to choose a repository. CodeDNA asks only for read access, and never stores more than it needs to import.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button disabled={busy} onClick={() => authorize(item.provider)} data-testid={`provider-authorize-${item.provider}`}>
                  {busy ? "Connecting…" : `Connect ${item.name}`}
                </Button>
              </CardContent>
            </Card>
          ),
        )
      )}

      {configured.length > 0 && configured.length < source.providers.length && connection === null && !source.github_connected ? (
        <p className="text-muted-foreground text-sm" data-testid="provider-unavailable">
          Not configured on this server: {source.providers.filter((p) => !p.configured).map((p) => p.name).join(", ")}.
        </p>
      ) : null}

      <ImportHistory projectId={project.id} imports={imports} />
    </div>
  );
}

function Connected({
  project,
  connection,
  providerName,
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
  connection: RepositoryProviderConnection;
  providerName: string;
  latest: RepositoryProviderImport | null;
  archived: boolean;
  busy: boolean;
  accountConnected: boolean;
  onImport: () => void;
  onBranch: (branch: string) => void;
  onDisconnect: () => void;
  onReauthorize: () => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const inProgress = active(latest);
  const { repository } = connection;

  return (
    <>
      <Card data-testid="provider-connection">
        <CardHeader>
          <CardTitle>
            <h2>Connected repository</h2>
          </CardTitle>
          <CardDescription>
            On {providerName}, connected {formatDateTime(connection.connected_at)}.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4">
          <dl className="grid gap-2 text-sm sm:grid-cols-[11rem_1fr]">
            <dt className="text-muted-foreground">Repository</dt>
            <dd className="font-medium" data-testid="provider-repository">
              {repository.full_name}
            </dd>
            <dt className="text-muted-foreground">Visibility</dt>
            <dd>{repository.private ? "Private" : "Public"}</dd>
            {repository.archived ? (
              <>
                <dt className="text-muted-foreground">On {providerName}</dt>
                <dd>Archived (read-only; imports still work)</dd>
              </>
            ) : null}
            <dt className="text-muted-foreground">Branch</dt>
            <dd data-testid="provider-branch">
              {connection.branch}
              {connection.branch === repository.default_branch ? <span className="text-muted-foreground"> (default)</span> : null}
            </dd>
            <dt className="text-muted-foreground">Last imported commit</dt>
            <dd>
              <code className="font-mono text-xs" title={connection.last_imported_commit_sha ?? undefined}>
                {shortSha(connection.last_imported_commit_sha)}
              </code>
            </dd>
            <dt className="text-muted-foreground">Last import</dt>
            <dd>{connection.last_imported_at === null ? "Never" : formatDateTime(connection.last_imported_at)}</dd>
          </dl>

          <LatestImport projectId={project.id} latest={latest} />

          {!accountConnected ? (
            <div className="grid gap-2 rounded-lg border p-3 text-sm" data-testid="provider-reauthorize">
              <p>Your {providerName} authorization is missing or expired. Connect your account again to import.</p>
              <Button variant="outline" className="w-fit" disabled={busy} onClick={onReauthorize}>
                Connect {providerName}
              </Button>
            </div>
          ) : null}

          {!archived ? (
            <div className="flex flex-wrap items-center gap-3">
              <Button onClick={onImport} disabled={busy || inProgress || !accountConnected} data-testid="provider-import">
                {inProgress ? "Importing…" : `Import from ${providerName}`}
              </Button>
              <span className="text-muted-foreground text-sm">Imports the current commit of {connection.branch}.</span>
            </div>
          ) : null}
        </CardContent>
      </Card>

      {!archived && accountConnected ? <BranchPicker key={connection.branch} projectId={project.id} current={connection.branch} busy={busy || inProgress} onChoose={onBranch} /> : null}

      <Card data-testid="provider-disconnect">
        <CardHeader>
          <CardTitle>
            <h2>Disconnect</h2>
          </CardTitle>
          <CardDescription>
            Ends {providerName} access for this project. Every imported source snapshot, analysis, CodeDNA result and growth record stays.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-3">
          {confirming ? (
            <>
              <Button variant="destructive" disabled={busy} onClick={onDisconnect} data-testid="provider-disconnect-confirm">
                Disconnect {repository.full_name}
              </Button>
              <Button variant="outline" disabled={busy} onClick={() => setConfirming(false)}>
                Keep connection
              </Button>
            </>
          ) : (
            <Button variant="outline" disabled={busy} onClick={() => setConfirming(true)} data-testid="provider-disconnect-start">
              Disconnect…
            </Button>
          )}
        </CardContent>
      </Card>
    </>
  );
}

function LatestImport({ projectId, latest }: { projectId: string; latest: RepositoryProviderImport | null }) {
  if (latest === null) {
    return (
      <p className="text-muted-foreground text-sm" data-testid="provider-latest-import">
        No import yet.
      </p>
    );
  }
  return (
    <div className="grid gap-1 rounded-lg border p-3 text-sm" data-testid="provider-latest-import" data-status={latest.status} aria-live="polite">
      <p className="font-medium">
        Latest import: {importStatusLabel(latest.status)}
        {latest.commit_sha !== null ? <span className="text-muted-foreground font-normal"> · commit {shortSha(latest.commit_sha)}</span> : null}
      </p>
      {latest.status === "SUCCEEDED" && latest.source_snapshot !== null ? (
        <p data-testid="provider-import-success">
          Source imported{latest.created_snapshot ? "" : " (this commit was already imported, so its snapshot is reused)"}. Ready for analysis:{" "}
          <Link href={`/app/projects/${projectId}`} className="underline underline-offset-4">
            source snapshot v{latest.source_snapshot.version}
          </Link>
          .
        </p>
      ) : null}
      {latest.status === "FAILED" ? (
        <p className="text-destructive" data-testid="provider-import-failure">
          {providerFailureMessage(latest.failure_code)}
        </p>
      ) : null}
      {latest.status === "CANCELLED" ? <p className="text-muted-foreground">Cancelled when the repository was disconnected.</p> : null}
    </div>
  );
}

function RepositoryPicker({
  providerKey,
  providerName,
  busy,
  onConnect,
}: {
  providerKey: RepositoryProviderKey;
  providerName: string;
  busy: boolean;
  onConnect: (repository: ProviderRepository) => void;
}) {
  const [repositories, setRepositories] = useState<ProviderRepository[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [selected, setSelected] = useState<ProviderRepository | null>(null);

  useEffect(() => {
    listProviderRepositories(providerKey, page)
      .then((result) => {
        setRepositories((current) => (page === 1 ? result.data : [...current, ...result.data]));
        setHasMore(result.meta.has_more);
        setError(null);
      })
      .catch((e: unknown) => setError(e))
      .finally(() => setLoading(false));
  }, [providerKey, page]);

  return (
    <Card data-testid={`provider-repository-picker-${providerKey}`}>
      <CardHeader>
        <CardTitle>
          <h2>Choose a {providerName} repository</h2>
        </CardTitle>
        <CardDescription>Repositories your {providerName} account can read. The default branch is used first; you can change it after connecting.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {error !== null ? <ApiErrorAlert error={error} /> : null}
        {loading && repositories.length === 0 ? <Skeleton className="h-24" /> : null}
        {!loading && repositories.length === 0 && error === null ? (
          <p className="text-muted-foreground text-sm" data-testid="provider-no-repositories">
            Your {providerName} account has no repositories CodeDNA can read.
          </p>
        ) : null}
        {repositories.length > 0 ? (
          <ul className="grid gap-2" data-testid="provider-repositories">
            {repositories.map((repository) => (
              <li key={repository.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm" data-testid="provider-repository-option">
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
          <div className="grid gap-2 rounded-lg border p-3 text-sm" data-testid="provider-connection-summary">
            <p>
              Connect <strong>{selected.full_name}</strong> ({selected.private ? "private" : "public"}) on branch <strong>{selected.default_branch}</strong>.
            </p>
            <Button className="w-fit" disabled={busy} onClick={() => onConnect(selected)} data-testid="provider-connect-repository">
              {busy ? "Connecting…" : "Connect repository"}
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

function BranchPicker({ projectId, current, busy, onChoose }: { projectId: string; current: string; busy: boolean; onChoose: (branch: string) => void }) {
  const [branches, setBranches] = useState<string[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [choice, setChoice] = useState(current);
  const loaded = useRef(0);

  useEffect(() => {
    const request = ++loaded.current;
    listProviderBranches(projectId, page)
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
    <Card data-testid="provider-branches">
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
              <select className="rounded-md border px-2 py-1" value={choice} onChange={(event) => setChoice(event.target.value)} data-testid="provider-branch-select">
                {branches.includes(current) ? null : <option value={current}>{current}</option>}
                {branches.map((branch) => (
                  <option key={branch} value={branch}>
                    {branch}
                  </option>
                ))}
              </select>
            </label>
            <Button variant="outline" disabled={busy || choice === current} onClick={() => onChoose(choice)} data-testid="provider-branch-save">
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

function ImportHistory({ projectId, imports }: { projectId: string; imports: RepositoryProviderImport[] }) {
  if (imports.length === 0) return null;
  return (
    <section aria-labelledby="provider-imports" className="grid gap-3" data-testid="provider-import-history">
      <h2 id="provider-imports" className="text-lg font-semibold">
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
              <tr key={item.id} className="border-t" data-testid="provider-import-row">
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
