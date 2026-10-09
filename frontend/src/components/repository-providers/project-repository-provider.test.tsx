import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { POLL_MS, ProjectRepositoryProviderView } from "@/components/repository-providers/project-repository-provider";
import type { Project, ProjectRepositoryProvider, RepositoryProviderImport } from "@/lib/api/types";
import { projectProvider, providerConnection, providerImport, providerRepository } from "@/test/repository-providers";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
const assign = vi.fn();
const originalLocation = window.location;
const base = `/api/v1/projects/${project.id}/repository-provider`;

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  assign.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
  Object.defineProperty(window, "location", { configurable: true, value: { ...originalLocation, hostname: "localhost", assign } });
  Object.defineProperty(document, "visibilityState", { configurable: true, get: () => "visible" });
});

afterEach(() => {
  vi.useRealTimers();
  Object.defineProperty(window, "location", { configurable: true, value: originalLocation });
});

interface Scenario {
  source?: ProjectRepositoryProvider | (() => ProjectRepositoryProvider);
  imports?: RepositoryProviderImport[];
  repositories?: (url: string) => Response;
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
    if (url.includes("/repository-provider/imports")) return jsonResponse(page(scenario.imports ?? []));
    if (url.includes("/repository-provider/branches")) return jsonResponse({ data: ["main", "develop"], meta: { page: 1, per_page: 50, has_more: false, default_branch: "main" } });
    if (url.includes("/repository-providers/")) {
      return scenario.repositories ? scenario.repositories(url) : jsonResponse({ data: [providerRepository()], meta: { page: 1, per_page: 30, has_more: false } });
    }
    if (url.endsWith("/repository-provider")) {
      return jsonResponse({ data: typeof scenario.source === "function" ? scenario.source() : (scenario.source ?? projectProvider()) });
    }
    return jsonResponse({ data: { ...project, ...scenario.projectOverride } });
  });
}

function writes() {
  return fetchMock.mock.calls
    .filter(([, init]) => (init?.method ?? "GET") !== "GET")
    .map(([input, init]) => ({ method: String(init?.method), url: String(input), body: init?.body ? JSON.parse(String(init.body)) : undefined }));
}

describe("ProjectRepositoryProviderView", () => {
  it("offers each configured provider and sends the browser to the provider only", async () => {
    respondWith({ write: () => jsonResponse({ data: { authorize_url: "https://gitlab.com/oauth/authorize?client_id=x&state=s", expires_at: "x" } }, 201) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading repository connection" })).toBeInTheDocument();
    expect(await screen.findByTestId("provider-connect-gitlab")).toHaveTextContent("Connect GitLab");
    expect(screen.getByTestId("provider-connect-bitbucket")).toHaveTextContent("Connect Bitbucket Cloud");
    expect(screen.getByTestId("provider-notice")).toHaveTextContent("Repository code is never executed");

    await userEvent.click(screen.getByTestId("provider-authorize-gitlab"));
    expect(writes()).toEqual([{ method: "POST", url: "/api/v1/repository-providers/gitlab/authorizations", body: { project_id: project.id } }]);
    expect(assign).toHaveBeenCalledWith("https://gitlab.com/oauth/authorize?client_id=x&state=s");
  });

  it("refuses to follow an unexpected authorization URL", async () => {
    respondWith({ write: () => jsonResponse({ data: { authorize_url: "javascript:alert(1)", expires_at: "x" } }, 201) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    await userEvent.click(await screen.findByTestId("provider-authorize-bitbucket"));
    expect(assign).not.toHaveBeenCalled();
    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  it("says when no provider is configured, and which ones are missing", async () => {
    respondWith({ source: projectProvider({}, { configured: false }) });
    const { unmount } = render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByTestId("provider-not-configured")).toHaveTextContent("Neither integration is configured on this server.");
    unmount();

    const partial = projectProvider();
    partial.providers[1].configured = false;
    respondWith({ source: partial });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByTestId("provider-unavailable")).toHaveTextContent("Not configured on this server: Bitbucket Cloud.");
    expect(screen.queryByTestId("provider-connect-bitbucket")).not.toBeInTheDocument();
  });

  it("points to GitHub when GitHub is the project's source", async () => {
    respondWith({ source: projectProvider({ github_connected: true }, { gitlab: true }) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByTestId("provider-github-connected")).toHaveTextContent("Disconnect GitHub first");
    expect(screen.queryByTestId("provider-repository-picker-gitlab")).not.toBeInTheDocument();
  });

  it("lists repositories page by page and connects the selected one by ID only", async () => {
    respondWith({
      source: projectProvider({}, { gitlab: true }),
      repositories: (url) =>
        url.includes("page=1")
          ? jsonResponse({ data: [providerRepository()], meta: { page: 1, per_page: 30, has_more: true } })
          : jsonResponse({ data: [providerRepository({ id: "7", full_name: "acme/other", private: false })], meta: { page: 2, per_page: 30, has_more: false } }),
      write: () => jsonResponse({ data: providerConnection() }, 201),
    });
    render(<ProjectRepositoryProviderView projectId={project.id} />);

    expect(await screen.findByText("acme/app")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "More repositories" }));
    expect(await screen.findByText("acme/other")).toBeInTheDocument();
    await userEvent.click(screen.getAllByRole("button", { name: "Select" })[1]);
    expect(screen.getByTestId("provider-connection-summary")).toHaveTextContent("Connect acme/other (public) on branch main.");
    await userEvent.click(screen.getByTestId("provider-connect-repository"));
    expect(writes()).toEqual([{ method: "POST", url: base, body: { provider: "gitlab", repository_id: "7" } }]);
  });

  it("shows an error from the provider listing without losing the page", async () => {
    respondWith({ source: projectProvider({}, { bitbucket: true }), repositories: () => apiErrorResponse(429, "PROVIDER_RATE_LIMITED", { headers: { "Retry-After": "30" } }) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("The repository provider is rate limiting requests. Try again in 30 seconds.");
    expect(screen.getByTestId("provider-repository-picker-bitbucket")).toBeInTheDocument();
  });

  it("shows the connection, never leaking IDs or tokens, and asks to re-authorize when the account is gone", async () => {
    respondWith({ source: projectProvider({ connection: providerConnection() }, { gitlab: false }) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByTestId("provider-repository")).toHaveTextContent("acme/app");
    expect(screen.getByTestId("provider-reauthorize")).toHaveTextContent("Your GitLab authorization is missing or expired.");
    expect(screen.getByTestId("provider-import")).toBeDisabled();
    expect(document.body.textContent).not.toContain("4242");
    expect(document.body.textContent).not.toContain("token");
  });

  it("imports, follows the import while it runs, pauses polling in a hidden tab, and shows the result", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    let latest: RepositoryProviderImport | null = null;
    respondWith({
      source: () => projectProvider({ connection: providerConnection(), latest_import: latest }, { gitlab: true }),
      write: () => {
        latest = providerImport({ status: "QUEUED", commit_sha: null, source_snapshot: null, created_snapshot: false, started_at: null, completed_at: null });
        return jsonResponse({ data: latest }, 202);
      },
    });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(await screen.findByTestId("provider-import"));

    expect(writes()).toEqual([{ method: "POST", url: `${base}/imports`, body: {} }]);
    expect(await screen.findByTestId("provider-latest-import")).toHaveTextContent("Latest import: Waiting to start");
    expect(screen.getByTestId("provider-import")).toBeDisabled();

    const gets = () => fetchMock.mock.calls.filter(([, init]) => (init?.method ?? "GET") === "GET").map(([input]) => new URL(String(input), "http://localhost").pathname);
    const before = gets().length;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS);
    });
    expect(gets().slice(before)).toEqual([base]);

    Object.defineProperty(document, "visibilityState", { configurable: true, get: () => "hidden" });
    act(() => {
      document.dispatchEvent(new Event("visibilitychange"));
    });
    const hidden = gets().length;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS * 3);
    });
    expect(gets().length).toBe(hidden);

    latest = providerImport();
    Object.defineProperty(document, "visibilityState", { configurable: true, get: () => "visible" });
    act(() => {
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS);
    });
    expect(await screen.findByTestId("provider-import-success")).toHaveTextContent("Ready for analysis");
    // Finished: the whole page (project, connection, import history) reloads once.
    await vi.waitFor(() => expect(gets().slice(hidden).filter((p) => p === `/api/v1/projects/${project.id}`)).toHaveLength(1));
    const after = gets().length;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS * 3);
    });
    expect(gets().length).toBe(after);
  });

  it("explains a failed import and a quota refusal", async () => {
    respondWith({
      source: projectProvider({ connection: providerConnection(), latest_import: providerImport({ status: "FAILED", failure_code: "SOURCE_ARCHIVE_TOO_LARGE", source_snapshot: null }) }, { gitlab: true }),
      write: () => apiErrorResponse(402, "QUOTA_EXCEEDED"),
    });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByTestId("provider-import-failure")).toHaveTextContent("larger than the source limit");
    await userEvent.click(screen.getByTestId("provider-import"));
    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  it("changes the branch and disconnects only after confirmation", async () => {
    respondWith({ source: projectProvider({ connection: providerConnection() }, { gitlab: true }), write: () => jsonResponse({ data: providerConnection() }) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);

    await userEvent.selectOptions(await screen.findByTestId("provider-branch-select"), "develop");
    await userEvent.click(screen.getByTestId("provider-branch-save"));
    await userEvent.click(screen.getByTestId("provider-disconnect-start"));
    expect(writes()).toEqual([{ method: "PATCH", url: base, body: { branch: "develop" } }]);
    await userEvent.click(screen.getByTestId("provider-disconnect-confirm"));
    expect(writes()[1]).toEqual({ method: "DELETE", url: base, body: undefined });
  });

  it("treats an invalid or inaccessible project as not found, and an expired session as a sign-in", async () => {
    const { unmount } = render(<ProjectRepositoryProviderView projectId="../etc" />);
    expect(screen.getByText("Project not found")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
    unmount();

    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const second = render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByText("Project not found")).toBeInTheDocument();
    second.unmount();

    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("keeps an archived project read-only", async () => {
    respondWith({ projectOverride: { status: "ARCHIVED" }, source: projectProvider({ connection: providerConnection() }, { gitlab: true }) });
    render(<ProjectRepositoryProviderView projectId={project.id} />);
    expect(await screen.findByTestId("provider-archived")).toBeInTheDocument();
    expect(screen.queryByTestId("provider-import")).not.toBeInTheDocument();
    expect(screen.getByTestId("provider-disconnect-start")).toBeEnabled();
  });
});
