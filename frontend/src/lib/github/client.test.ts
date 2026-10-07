import { beforeEach, describe, expect, it, vi } from "vitest";

import { changeGitHubBranch, connectGitHubRepository, getGitHubImport, listGitHubRepositories, requestGitHubImport, startGitHubAuthorization } from "@/lib/github/client";
import { importFailureMessage, isSafeRedirect, shortSha } from "@/lib/github/format";
import { connection, githubImport } from "@/test/github";
import { jsonResponse, project } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token; path=/";
});

describe("github client", () => {
  it("sends only a repository ID and a branch name", async () => {
    fetchMock.mockImplementation(async () => jsonResponse({ data: connection() }));

    await connectGitHubRepository(project.id, 1296269);
    await connectGitHubRepository(project.id, 1296269, "feature/x");
    await changeGitHubBranch(project.id, "develop");
    await requestGitHubImport(project.id);

    expect(fetchMock.mock.calls.map(([url, init]) => [String(url), init?.method, JSON.parse(String(init?.body))])).toEqual([
      [`/api/v1/projects/${project.id}/github`, "POST", { repository_id: 1296269 }],
      [`/api/v1/projects/${project.id}/github`, "POST", { repository_id: 1296269, branch: "feature/x" }],
      [`/api/v1/projects/${project.id}/github`, "PATCH", { branch: "develop" }],
      [`/api/v1/projects/${project.id}/github/imports`, "POST", {}],
    ]);
  });

  it("refuses unsafe identifiers before sending anything", async () => {
    await expect(connectGitHubRepository("../x", 1)).rejects.toThrow("Invalid project ID.");
    await expect(connectGitHubRepository(project.id, 0)).rejects.toThrow("Invalid repository.");
    await expect(connectGitHubRepository(project.id, 1.5)).rejects.toThrow("Invalid repository.");
    await expect(changeGitHubBranch(project.id, "main; rm -rf /")).rejects.toThrow("Invalid branch.");
    await expect(getGitHubImport(project.id, "../../x")).rejects.toThrow("Invalid import ID.");
    expect(() => listGitHubRepositories(-1)).toThrow("Invalid installation.");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("never sends an invalid return project", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: { authorize_url: "https://github.com/x", install_url: "https://github.com/y", expires_at: "z" } }, 201));

    await startGitHubAuthorization("../evil");

    expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toEqual({ project_id: "" });
  });

  it("reads an import", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: githubImport() }));

    await expect(getGitHubImport(project.id, githubImport().id.toUpperCase())).resolves.toEqual(githubImport());
    expect(String(fetchMock.mock.calls[0][0])).toBe(`/api/v1/projects/${project.id}/github/imports/${githubImport().id}`);
  });
});

describe("github format", () => {
  it("formats commits, failures and redirects", () => {
    expect(shortSha("6dcb09b5b57875f334f61aebed695e2e4193db5e")).toBe("6dcb09b");
    expect(shortSha(null)).toBe("—");
    expect(importFailureMessage("<script>")).toBe("The import could not be completed. Try again.");
    expect(importFailureMessage(null)).toBe("The import could not be completed. Try again.");
    expect(isSafeRedirect("https://github.com/login/oauth/authorize")).toBe(true);
    expect(isSafeRedirect("javascript:alert(1)")).toBe(false);
    expect(isSafeRedirect("data:text/html,<script>alert(1)</script>")).toBe(false);
    expect(isSafeRedirect("vbscript:x")).toBe(false);
    expect(isSafeRedirect("not a url")).toBe(false);
  });
});
