import { act, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { POLL_MS, ProjectGitHubView } from "@/components/github/project-github";
import type { GitHubImport, GitHubInstallation, GitHubRepository, Project, ProjectGitHub } from "@/lib/api/types";
import { connection, githubImport, projectGitHub, repository, SHA } from "@/test/github";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
const assign = vi.fn();
const originalLocation = window.location;

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  assign.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
  Object.defineProperty(window, "location", { configurable: true, value: { ...originalLocation, hostname: "localhost", assign } });
});

afterEach(() => {
  vi.useRealTimers();
  Object.defineProperty(window, "location", { configurable: true, value: originalLocation });
});

interface Scenario {
  github?: ProjectGitHub | (() => ProjectGitHub);
  imports?: GitHubImport[];
  installations?: GitHubInstallation[] | Response;
  repositories?: (page: number) => Response;
  branches?: Response;
  projectOverride?: Partial<Project>;
  write?: (method: string, url: string, body: unknown) => Response;
}

function respondWith(scenario: Scenario = {}) {
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    const method = init?.method ?? "GET";
    if (method !== "GET") {
      const body = init?.body ? JSON.parse(String(init.body)) : undefined;
      return scenario.write ? scenario.write(method, url, body) : jsonResponse({ data: {} });
    }
    if (url.includes("/github/imports")) return jsonResponse(page(scenario.imports ?? []));
    if (url.includes("/github/branches")) return scenario.branches ?? jsonResponse({ data: [{ name: "main", protected: true }, { name: "develop", protected: false }], meta: { page: 1, per_page: 50, has_more: false, default_branch: "main" } });
    if (url.includes("/repositories")) {
      const pageNumber = Number(new URL(url, "http://localhost").searchParams.get("page"));
      return scenario.repositories ? scenario.repositories(pageNumber) : jsonResponse({ data: [repository()], meta: { page: 1, per_page: 30, has_more: false } });
    }
    if (url.endsWith("/github/installations")) {
      const installations = scenario.installations ?? [{ id: 77, account: "octo-org", account_type: "Organization", repository_selection: "selected" }];
      return installations instanceof Response ? installations : jsonResponse({ data: installations });
    }
    if (url.endsWith("/github")) {
      const github = typeof scenario.github === "function" ? scenario.github() : (scenario.github ?? projectGitHub());
      return jsonResponse({ data: github });
    }
    return jsonResponse({ data: { ...project, ...scenario.projectOverride } });
  });
}

function writes(): { method: string; url: string; body: unknown }[] {
  return fetchMock.mock.calls
    .filter(([, init]) => (init?.method ?? "GET") !== "GET")
    .map(([input, init]) => ({ method: String(init?.method), url: String(input), body: init?.body ? JSON.parse(String(init.body)) : undefined }));
}

describe("ProjectGitHubView", () => {
  it("shows a loading state, then a project without a GitHub connection", async () => {
    respondWith({ github: projectGitHub({ account_connected: false }) });
    render(<ProjectGitHubView projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading GitHub connection" })).toBeInTheDocument();
    expect(await screen.findByTestId("github-not-connected")).toHaveTextContent("Not connected");
    expect(screen.getByTestId("github-notice")).toHaveTextContent("Repository code is never executed, and disconnecting never deletes imported snapshots or analyses.");
    expect(screen.queryByTestId("github-import-history")).not.toBeInTheDocument();
  });

  it("starts the authorization and sends the browser to GitHub only", async () => {
    respondWith({
      github: projectGitHub({ account_connected: false }),
      write: () => jsonResponse({ data: { authorize_url: "https://github.com/login/oauth/authorize?client_id=x&state=s", install_url: "https://github.com/apps/codedna/installations/new?state=s", expires_at: "2026-10-16T09:10:00Z" } }, 201),
    });
    render(<ProjectGitHubView projectId={project.id} />);

    await userEvent.click(await screen.findByTestId("github-connect"));

    expect(writes()).toEqual([{ method: "POST", url: "/api/v1/github/authorizations", body: { project_id: project.id } }]);
    expect(assign).toHaveBeenCalledWith("https://github.com/login/oauth/authorize?client_id=x&state=s");
  });

  it("refuses to follow an unexpected authorization URL", async () => {
    respondWith({
      github: projectGitHub({ account_connected: false }),
      write: () => jsonResponse({ data: { authorize_url: "javascript:alert(1)", install_url: "javascript:alert(1)", expires_at: "x" } }, 201),
    });
    render(<ProjectGitHubView projectId={project.id} />);

    await userEvent.click(await screen.findByTestId("github-connect"));

    expect(assign).not.toHaveBeenCalled();
    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  it("says when GitHub is not configured", async () => {
    respondWith({ github: projectGitHub({ configured: false, account_connected: false }) });
    render(<ProjectGitHubView projectId={project.id} />);

    expect(await screen.findByTestId("github-not-configured")).toHaveTextContent("GitHub integration is not configured on this server.");
    expect(screen.queryByTestId("github-connect")).not.toBeInTheDocument();
  });

  it("lists repositories with owner, visibility, default branch and archived state, then connects the chosen one", async () => {
    const repos: GitHubRepository[] = [repository(), repository({ id: 2, name: "legacy", full_name: "octo-org/legacy", private: false, archived: true, default_branch: "master" })];
    respondWith({
      repositories: () => jsonResponse({ data: repos, meta: { page: 1, per_page: 30, has_more: false } }),
      write: () => jsonResponse({ data: connection() }, 201),
    });
    render(<ProjectGitHubView projectId={project.id} />);

    const options = await screen.findAllByTestId("github-repository-option");
    expect(options[0]).toHaveTextContent("octo-org/billing-service");
    expect(options[0]).toHaveTextContent("Private · default branch main");
    expect(options[1]).toHaveTextContent("Public · default branch master · archived");

    await userEvent.click(within(options[1]).getByRole("button", { name: "Select" }));
    expect(screen.getByTestId("github-connection-summary")).toHaveTextContent("Connect octo-org/legacy (public) on branch master.");
    await userEvent.click(screen.getByTestId("github-connect-repository"));

    // Only the repository ID is sent: owner, name and installation come from GitHub.
    expect(writes()).toEqual([{ method: "POST", url: `/api/v1/projects/${project.id}/github`, body: { repository_id: 2 } }]);
  });

  it("pages through repositories", async () => {
    respondWith({
      repositories: (p) =>
        jsonResponse({ data: [repository({ id: p, name: `repo-${p}`, full_name: `octo-org/repo-${p}` })], meta: { page: p, per_page: 30, has_more: p < 2 } }),
    });
    render(<ProjectGitHubView projectId={project.id} />);

    await userEvent.click(await screen.findByRole("button", { name: "More repositories" }));

    expect(await screen.findByText("octo-org/repo-2")).toBeInTheDocument();
    expect(screen.getByText("octo-org/repo-1")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "More repositories" })).not.toBeInTheDocument();
  });

  it("offers to install the App when no installation is accessible, and explains empty installations", async () => {
    respondWith({ installations: [] });
    const { unmount } = render(<ProjectGitHubView projectId={project.id} />);
    expect(await screen.findByTestId("github-no-installation")).toHaveTextContent("The CodeDNA GitHub App is not installed");
    unmount();

    respondWith({ repositories: () => jsonResponse({ data: [], meta: { page: 1, per_page: 30, has_more: false } }) });
    render(<ProjectGitHubView projectId={project.id} />);
    expect(await screen.findByTestId("github-no-repositories")).toHaveTextContent("No repositories are available");
  });

  it("shows GitHub errors while browsing, including rate limits with their delay", async () => {
    respondWith({ repositories: () => apiErrorResponse(429, "GITHUB_RATE_LIMITED", { headers: { "retry-after": "90" } }) });
    render(<ProjectGitHubView projectId={project.id} />);

    expect(await screen.findByRole("alert")).toHaveTextContent("GitHub is rate limiting requests. Try again in 2 minutes.");
  });

  it("shows the connection summary without internal identifiers", async () => {
    respondWith({ github: projectGitHub({ connection: connection({ last_imported_commit_sha: SHA, last_imported_at: "2026-10-16T09:05:09Z" }), latest_import: githubImport() }), imports: [githubImport()] });
    render(<ProjectGitHubView projectId={project.id} />);

    const card = await screen.findByTestId("github-connection");
    expect(card).toHaveTextContent("Repository details as last verified with GitHub on Oct 16, 2026");
    expect(within(card).getByTestId("github-repository")).toHaveTextContent("octo-org/billing-service");
    expect(card).toHaveTextContent("Private");
    expect(within(card).getByTestId("github-branch")).toHaveTextContent("main (default)");
    expect(within(card).getByTestId("github-last-commit")).toHaveTextContent("6dcb09b");
    expect(within(card).getByTestId("github-import-success")).toHaveTextContent("Source imported. Ready for analysis: source snapshot v3.");
    expect(within(card).getByRole("link", { name: "source snapshot v3" })).toHaveAttribute("href", `/app/projects/${project.id}`);
    const text = document.body.textContent ?? "";
    // No repository or connection ID, installation, API URL or credential on the page.
    for (const hidden of ["1296269", connection().id, "installation", "api.github", "token"]) {
      expect(text).not.toContain(hidden);
    }
    expect(screen.getAllByTestId("github-import-row")[0]).toHaveTextContent("Imported · snapshot v3");
  });

  it("shows an archived repository on GitHub as importable", async () => {
    respondWith({ github: projectGitHub({ connection: connection({ repository: repository({ archived: true }) }) }) });
    render(<ProjectGitHubView projectId={project.id} />);

    expect(await screen.findByTestId("github-repository-archived")).toHaveTextContent("Archived (read-only on GitHub; imports still work)");
    expect(screen.getByTestId("github-import")).toBeEnabled();
  });

  it("imports, follows the import while it runs, and shows the result", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    let latest: GitHubImport | null = null;
    respondWith({
      github: () => projectGitHub({ connection: connection(), latest_import: latest }),
      write: () => {
        latest = githubImport({ status: "QUEUED", commit_sha: null, source_snapshot: null, created_snapshot: false, completed_at: null, started_at: null });
        return jsonResponse({ data: latest }, 202);
      },
    });
    render(<ProjectGitHubView projectId={project.id} />);
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(await screen.findByTestId("github-import"));

    expect(writes()).toEqual([{ method: "POST", url: `/api/v1/projects/${project.id}/github/imports`, body: {} }]);
    expect(await screen.findByTestId("github-latest-import")).toHaveTextContent("Latest import: Waiting to start");
    expect(screen.getByTestId("github-import")).toBeDisabled();

    // The page polls every POLL_MS while the import is in progress.
    latest = githubImport();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS);
    });
    expect(await screen.findByTestId("github-import-success")).toHaveTextContent("Ready for analysis");
  });

  it("keeps Import disabled until the page has reloaded, so one click sends one request", async () => {
    let posted = false;
    respondWith({
      github: () => projectGitHub({ connection: connection(), latest_import: null }),
      write: () => {
        posted = true;
        return jsonResponse({ data: githubImport({ status: "QUEUED", commit_sha: null, source_snapshot: null, created_snapshot: false, completed_at: null, started_at: null }) }, 202);
      },
    });
    const respond = fetchMock.getMockImplementation()!;
    fetchMock.mockImplementation((input, init) =>
      posted && (init?.method ?? "GET") === "GET" && String(input).endsWith("/github") ? new Promise<Response>(() => {}) : respond(input, init),
    );
    render(<ProjectGitHubView projectId={project.id} />);
    const button = await screen.findByTestId("github-import");
    await userEvent.click(button);
    await userEvent.click(button);

    expect(writes()).toHaveLength(1);
    expect(screen.getByTestId("github-import")).toBeDisabled();
  });

  it("explains a reused snapshot and every failure in plain words", async () => {
    respondWith({ github: projectGitHub({ connection: connection(), latest_import: githubImport({ created_snapshot: false }) }) });
    const { unmount } = render(<ProjectGitHubView projectId={project.id} />);
    expect(await screen.findByTestId("github-import-success")).toHaveTextContent("this commit was already imported, so its snapshot is reused");
    unmount();

    const failures: [string, string][] = [
      ["GITHUB_BRANCH_NOT_FOUND", "The branch no longer exists. Choose another branch; CodeDNA never switches branches on its own."],
      ["GITHUB_REPOSITORY_NOT_FOUND", "The repository is no longer accessible through the CodeDNA GitHub App."],
      ["GITHUB_RATE_LIMITED", "GitHub was rate limiting requests."],
      ["SOURCE_FILE_COUNT_EXCEEDED", "The repository contains more files than allowed."],
      ["SOME_FUTURE_CODE", "The import could not be completed. Try again."],
    ];
    for (const [code, message] of failures) {
      respondWith({ github: projectGitHub({ connection: connection(), latest_import: githubImport({ status: "FAILED", failure_code: code, source_snapshot: null, commit_sha: null }) }) });
      const view = render(<ProjectGitHubView projectId={project.id} />);
      expect(await screen.findByTestId("github-import-failure")).toHaveTextContent(message);
      view.unmount();
    }
  });

  it("shows import request errors without leaving the page", async () => {
    respondWith({ github: projectGitHub({ connection: connection() }), write: () => apiErrorResponse(422, "GITHUB_REPOSITORY_NOT_FOUND") });
    render(<ProjectGitHubView projectId={project.id} />);

    await userEvent.click(await screen.findByTestId("github-import"));

    expect(await screen.findByRole("alert")).toHaveTextContent("This repository is not available to you through the CodeDNA GitHub App.");
    expect(screen.getByTestId("github-connection")).toBeInTheDocument();
  });

  it("lists branches and changes the branch with only its name", async () => {
    respondWith({ github: projectGitHub({ connection: connection() }), write: () => jsonResponse({ data: connection({ branch: "develop" }) }) });
    render(<ProjectGitHubView projectId={project.id} />);

    const select = await screen.findByTestId("github-branch-select");
    expect(within(select).getAllByRole("option").map((o) => o.textContent)).toEqual(["main (protected)", "develop"]);
    expect(screen.getByTestId("github-branch-save")).toBeDisabled();
    await userEvent.selectOptions(select, "develop");
    await userEvent.click(screen.getByTestId("github-branch-save"));

    expect(writes()).toEqual([{ method: "PATCH", url: `/api/v1/projects/${project.id}/github`, body: { branch: "develop" } }]);
  });

  it("asks to connect GitHub again when the authorization is gone", async () => {
    respondWith({ github: projectGitHub({ account_connected: false, connection: connection() }) });
    render(<ProjectGitHubView projectId={project.id} />);

    expect(await screen.findByTestId("github-reauthorize")).toHaveTextContent("Connect GitHub again to import.");
    expect(screen.getByTestId("github-import")).toBeDisabled();
    expect(screen.queryByTestId("github-branches")).not.toBeInTheDocument();
  });

  it("disconnects in two steps and keeps the history visible", async () => {
    let active = true;
    respondWith({
      github: () => projectGitHub({ connection: active ? connection() : null }),
      imports: [githubImport()],
      write: () => {
        active = false;
        return jsonResponse({ data: connection({ status: "DISCONNECTED", disconnected_at: "2026-10-16T10:00:00Z" }) });
      },
    });
    render(<ProjectGitHubView projectId={project.id} />);

    await userEvent.click(await screen.findByTestId("github-disconnect-start"));
    expect(writes()).toEqual([]);
    await userEvent.click(screen.getByTestId("github-disconnect-confirm"));

    expect(writes()).toEqual([{ method: "DELETE", url: `/api/v1/projects/${project.id}/github`, body: undefined }]);
    expect(await screen.findByTestId("github-repository-picker")).toBeInTheDocument();
    expect(screen.getAllByTestId("github-import-row")).toHaveLength(1);
  });

  it("keeps archived projects read-only except for disconnecting", async () => {
    respondWith({ github: projectGitHub({ connection: connection(), latest_import: githubImport() }), projectOverride: { status: "ARCHIVED" } });
    render(<ProjectGitHubView projectId={project.id} />);

    expect(await screen.findByTestId("github-archived")).toBeInTheDocument();
    expect(screen.queryByTestId("github-import")).not.toBeInTheDocument();
    expect(screen.queryByTestId("github-branches")).not.toBeInTheDocument();
    expect(screen.getByTestId("github-disconnect-start")).toBeEnabled();
  });

  it("shows a callback notice", async () => {
    respondWith();
    render(<ProjectGitHubView projectId={project.id} notice="GitHub is connected. Choose a repository to import." />);

    expect(await screen.findByTestId("github-callback-notice")).toHaveTextContent("GitHub is connected.");
  });

  it("treats invalid, missing and foreign projects alike, and handles session and server errors", async () => {
    render(<ProjectGitHubView projectId="../x" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();

    fetchMock.mockImplementation(async () => apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const second = render(<ProjectGitHubView projectId={project.id} />);
    expect(await screen.findAllByRole("heading", { name: "Project not found" })).not.toHaveLength(0);
    second.unmount();

    fetchMock.mockImplementation(async () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    const third = render(<ProjectGitHubView projectId={project.id} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
    third.unmount();

    fetchMock.mockImplementation(async () => apiErrorResponse(500, "INTERNAL_ERROR"));
    render(<ProjectGitHubView projectId={project.id} />);
    expect(await screen.findByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
