import { api } from "@/lib/api/client";
import type {
  DataEnvelope,
  GitHubPage,
  Paginated,
  ProjectRepositoryProvider,
  ProviderRepository,
  RepositoryProviderAccountStatus,
  RepositoryProviderAuthorizationStart,
  RepositoryProviderConnection,
  RepositoryProviderImport,
  RepositoryProviderKey,
  RepositoryProviderUnlink,
} from "@/lib/api/types";
import { REPOSITORY_PROVIDERS } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side access to GitLab and Bitbucket Cloud (Phase 28). The browser
 * never holds a provider token or URL: it sends a provider key, a repository
 * ID from the server's own listing and a branch name at most; the server
 * verifies everything with the provider as the signed-in user.
 */

const REPOSITORY_ID = /^[0-9A-Za-z{}/-]{1,80}$/;

export function isRepositoryProvider(value: unknown): value is RepositoryProviderKey {
  return typeof value === "string" && (REPOSITORY_PROVIDERS as readonly string[]).includes(value);
}

function provider(key: RepositoryProviderKey): RepositoryProviderKey {
  if (!isRepositoryProvider(key)) {
    throw new Error("Unknown repository provider.");
  }
  return key;
}

function projectPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/repository-provider${suffix}`;
}

function checkedBranch(branch: string): string {
  // Control characters and empty names never reach the server; it verifies the rest with the provider.
  if (branch.length < 1 || branch.length > 255 || /[\u0000-\u001f\u007f]/.test(branch)) {
    throw new Error("Invalid branch.");
  }
  return branch;
}

function page(value: number): number {
  return Number.isSafeInteger(value) && value >= 1 ? value : 1;
}

export async function listRepositoryProviderAccounts(): Promise<RepositoryProviderAccountStatus[]> {
  return (await api.get<DataEnvelope<RepositoryProviderAccountStatus[]>>("/api/v1/repository-providers")).data;
}

/** Starts an authorization; the result holds the provider URL to send the browser to. */
export async function startProviderAuthorization(key: RepositoryProviderKey, projectId?: string): Promise<RepositoryProviderAuthorizationStart> {
  const body = projectId === undefined ? {} : { project_id: isProjectId(projectId) ? projectId.toLowerCase() : "" };
  return (await api.post<DataEnvelope<RepositoryProviderAuthorizationStart>>(`/api/v1/repository-providers/${provider(key)}/authorizations`, body)).data;
}

/** Completes an authorization with what the provider put in the callback URL. */
export async function completeProviderAuthorization(key: RepositoryProviderKey, state: string, code: string): Promise<{ connected: boolean; project_id: string | null }> {
  return (await api.post<DataEnvelope<{ connected: boolean; project_id: string | null }>>(`/api/v1/repository-providers/${provider(key)}/callback`, { state, code })).data;
}

export async function unlinkRepositoryProvider(key: RepositoryProviderKey): Promise<RepositoryProviderUnlink> {
  return (await api.delete<DataEnvelope<RepositoryProviderUnlink>>(`/api/v1/repository-providers/${provider(key)}`)).data;
}

export function listProviderRepositories(key: RepositoryProviderKey, pageNumber = 1, perPage = 30): Promise<GitHubPage<ProviderRepository>> {
  return api.get<GitHubPage<ProviderRepository>>(`/api/v1/repository-providers/${provider(key)}/repositories?page=${page(pageNumber)}&per_page=${perPage}`);
}

export async function getProjectRepositoryProvider(projectId: string): Promise<ProjectRepositoryProvider> {
  return (await api.get<DataEnvelope<ProjectRepositoryProvider>>(projectPath(projectId))).data;
}

export async function connectProviderRepository(projectId: string, key: RepositoryProviderKey, repositoryId: string, branch?: string): Promise<RepositoryProviderConnection> {
  if (!REPOSITORY_ID.test(repositoryId)) {
    throw new Error("Invalid repository.");
  }
  const body = { provider: provider(key), repository_id: repositoryId, ...(branch === undefined ? {} : { branch: checkedBranch(branch) }) };
  return (await api.post<DataEnvelope<RepositoryProviderConnection>>(projectPath(projectId), body)).data;
}

export async function changeProviderBranch(projectId: string, branch: string): Promise<RepositoryProviderConnection> {
  return (await api.patch<DataEnvelope<RepositoryProviderConnection>>(projectPath(projectId), { branch: checkedBranch(branch) })).data;
}

export async function disconnectProviderRepository(projectId: string): Promise<RepositoryProviderConnection> {
  return (await api.delete<DataEnvelope<RepositoryProviderConnection>>(projectPath(projectId))).data;
}

export function listProviderBranches(projectId: string, pageNumber = 1, perPage = 50): Promise<GitHubPage<string>> {
  return api.get<GitHubPage<string>>(projectPath(projectId, `/branches?page=${page(pageNumber)}&per_page=${perPage}`));
}

/** Imports the connected branch's current commit. Sends nothing. */
export async function requestProviderImport(projectId: string): Promise<RepositoryProviderImport> {
  return (await api.post<DataEnvelope<RepositoryProviderImport>>(projectPath(projectId, "/imports"), {})).data;
}

export function listProviderImports(projectId: string, pageNumber = 1, perPage = 10): Promise<Paginated<RepositoryProviderImport>> {
  return api.get<Paginated<RepositoryProviderImport>>(projectPath(projectId, `/imports?page=${page(pageNumber)}&per_page=${perPage}`));
}
