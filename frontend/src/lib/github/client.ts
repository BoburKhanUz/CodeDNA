import { api } from "@/lib/api/client";
import type {
  DataEnvelope,
  GitHubAccountStatus,
  GitHubAuthorizationStart,
  GitHubBranch,
  GitHubConnection,
  GitHubImport,
  GitHubInstallation,
  GitHubPage,
  GitHubRepository,
  Paginated,
  ProjectGitHub,
} from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side access to the GitHub integration (Phase 19). The browser
 * never holds a GitHub token: it sends a repository ID and a branch name at
 * most, and everything else is verified by the server with GitHub.
 */

const BRANCH = /^[A-Za-z0-9._/-]{1,255}$/;

function projectPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/github${suffix}`;
}

function positiveId(value: number, what: string): number {
  if (!Number.isSafeInteger(value) || value < 1) {
    throw new Error(`Invalid ${what}.`);
  }
  return value;
}

function checkedBranch(branch: string): string {
  if (!BRANCH.test(branch)) {
    throw new Error("Invalid branch.");
  }
  return branch;
}

export async function getGitHubAccount(): Promise<GitHubAccountStatus> {
  return (await api.get<DataEnvelope<GitHubAccountStatus>>("/api/v1/github")).data;
}

/** Starts an authorization; the result holds GitHub URLs to send the browser to. */
export async function startGitHubAuthorization(projectId?: string): Promise<GitHubAuthorizationStart> {
  const body = projectId === undefined ? {} : { project_id: isProjectId(projectId) ? projectId.toLowerCase() : "" };
  return (await api.post<DataEnvelope<GitHubAuthorizationStart>>("/api/v1/github/authorizations", body)).data;
}

/** Completes an authorization with what GitHub put in the callback URL. */
export async function completeGitHubAuthorization(state: string, code: string): Promise<{ connected: boolean; project_id: string | null }> {
  return (await api.post<DataEnvelope<{ connected: boolean; project_id: string | null }>>("/api/v1/github/callback", { state, code })).data;
}

export function unlinkGitHub(): Promise<void> {
  return api.delete<void>("/api/v1/github");
}

export async function listGitHubInstallations(): Promise<GitHubInstallation[]> {
  return (await api.get<DataEnvelope<GitHubInstallation[]>>("/api/v1/github/installations")).data;
}

export function listGitHubRepositories(installationId: number, page = 1, perPage = 30): Promise<GitHubPage<GitHubRepository>> {
  return api.get<GitHubPage<GitHubRepository>>(
    `/api/v1/github/installations/${positiveId(installationId, "installation")}/repositories?page=${page}&per_page=${perPage}`,
  );
}

export async function getProjectGitHub(projectId: string): Promise<ProjectGitHub> {
  return (await api.get<DataEnvelope<ProjectGitHub>>(projectPath(projectId))).data;
}

export async function connectGitHubRepository(projectId: string, repositoryId: number, branch?: string): Promise<GitHubConnection> {
  const body = branch === undefined ? { repository_id: positiveId(repositoryId, "repository") } : { repository_id: positiveId(repositoryId, "repository"), branch: checkedBranch(branch) };
  return (await api.post<DataEnvelope<GitHubConnection>>(projectPath(projectId), body)).data;
}

export async function changeGitHubBranch(projectId: string, branch: string): Promise<GitHubConnection> {
  return (await api.patch<DataEnvelope<GitHubConnection>>(projectPath(projectId), { branch: checkedBranch(branch) })).data;
}

export async function disconnectGitHub(projectId: string): Promise<GitHubConnection> {
  return (await api.delete<DataEnvelope<GitHubConnection>>(projectPath(projectId))).data;
}

export function listGitHubBranches(projectId: string, page = 1, perPage = 50): Promise<GitHubPage<GitHubBranch>> {
  return api.get<GitHubPage<GitHubBranch>>(projectPath(projectId, `/branches?page=${page}&per_page=${perPage}`));
}

/** Imports the connected branch's current commit. Sends nothing. */
export async function requestGitHubImport(projectId: string): Promise<GitHubImport> {
  return (await api.post<DataEnvelope<GitHubImport>>(projectPath(projectId, "/imports"), {})).data;
}

export function listGitHubImports(projectId: string, page = 1, perPage = 10): Promise<Paginated<GitHubImport>> {
  return api.get<Paginated<GitHubImport>>(projectPath(projectId, `/imports?page=${page}&per_page=${perPage}`));
}

export async function getGitHubImport(projectId: string, importId: string): Promise<GitHubImport> {
  if (!isProjectId(importId)) {
    throw new Error("Invalid import ID.");
  }
  return (await api.get<DataEnvelope<GitHubImport>>(projectPath(projectId, `/imports/${importId.toLowerCase()}`))).data;
}
