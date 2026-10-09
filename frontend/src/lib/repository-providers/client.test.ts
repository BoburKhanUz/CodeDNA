import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  changeProviderBranch,
  completeProviderAuthorization,
  connectProviderRepository,
  isRepositoryProvider,
  listProviderRepositories,
  requestProviderImport,
  startProviderAuthorization,
  unlinkRepositoryProvider,
} from "@/lib/repository-providers/client";
import { providerFailureMessage } from "@/lib/repository-providers/format";
import type { RepositoryProviderKey } from "@/lib/api/types";
import { providerConnection } from "@/test/repository-providers";
import { jsonResponse, project } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();
const BB = "{11111111-2222-3333-4444-555555555555}/{66666666-7777-8888-9999-000000000000}";

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token; path=/";
});

const calls = () => fetchMock.mock.calls.map(([url, init]) => [String(url), init?.method, init?.body === undefined ? undefined : JSON.parse(String(init.body))]);

describe("repository provider client", () => {
  it("sends only a provider, a repository ID from the listing and a branch name", async () => {
    fetchMock.mockImplementation(async () => jsonResponse({ data: providerConnection() }));

    await connectProviderRepository(project.id, "gitlab", "4242");
    await connectProviderRepository(project.id, "bitbucket", BB, "feature/x");
    await changeProviderBranch(project.id, "develop");
    await requestProviderImport(project.id);

    expect(calls()).toEqual([
      [`/api/v1/projects/${project.id}/repository-provider`, "POST", { provider: "gitlab", repository_id: "4242" }],
      [`/api/v1/projects/${project.id}/repository-provider`, "POST", { provider: "bitbucket", repository_id: BB, branch: "feature/x" }],
      [`/api/v1/projects/${project.id}/repository-provider`, "PATCH", { branch: "develop" }],
      [`/api/v1/projects/${project.id}/repository-provider/imports`, "POST", {}],
    ]);
  });

  it("uses only the provider-scoped authorization endpoints", async () => {
    fetchMock.mockImplementation(async () => jsonResponse({ data: { authorize_url: "https://gitlab.com/oauth/authorize?x", expires_at: "x", connected: true, project_id: null, revocation: "REVOKED" } }));

    await startProviderAuthorization("gitlab", project.id);
    await completeProviderAuthorization("bitbucket", "s".repeat(43), "code");
    await unlinkRepositoryProvider("gitlab");
    await listProviderRepositories("bitbucket", 2);

    expect(calls()).toEqual([
      ["/api/v1/repository-providers/gitlab/authorizations", "POST", { project_id: project.id }],
      ["/api/v1/repository-providers/bitbucket/callback", "POST", { state: "s".repeat(43), code: "code" }],
      ["/api/v1/repository-providers/gitlab", "DELETE", undefined],
      ["/api/v1/repository-providers/bitbucket/repositories?page=2&per_page=30", "GET", undefined],
    ]);
  });

  it("refuses unsafe identifiers and unknown providers before sending anything", async () => {
    await expect(connectProviderRepository("../x", "gitlab", "1")).rejects.toThrow("Invalid project ID.");
    await expect(connectProviderRepository(project.id, "gitlab", "../../admin?x=1")).rejects.toThrow("Invalid repository.");
    await expect(connectProviderRepository(project.id, "gitlab", "https://evil.example/x")).rejects.toThrow("Invalid repository.");
    await expect(connectProviderRepository(project.id, "github" as RepositoryProviderKey, "1")).rejects.toThrow("Unknown repository provider.");
    await expect(changeProviderBranch(project.id, "")).rejects.toThrow("Invalid branch.");
    await expect(changeProviderBranch(project.id, "main\nx")).rejects.toThrow("Invalid branch.");
    await expect(startProviderAuthorization("../x" as RepositoryProviderKey)).rejects.toThrow("Unknown repository provider.");
    expect(fetchMock).not.toHaveBeenCalled();
    expect(isRepositoryProvider("gitlab")).toBe(true);
    expect(isRepositoryProvider("bitbucket-server")).toBe(false);
  });

  it("explains failure codes without showing raw text", () => {
    expect(providerFailureMessage("PROVIDER_BRANCH_NOT_FOUND")).toContain("never switches branches");
    expect(providerFailureMessage("SOURCE_ARCHIVE_UNSAFE")).toContain("symbolic link");
    expect(providerFailureMessage("<script>")).toBe(providerFailureMessage("PROVIDER_IMPORT_FAILED"));
    expect(providerFailureMessage(null)).toBe(providerFailureMessage("PROVIDER_IMPORT_FAILED"));
  });
});
