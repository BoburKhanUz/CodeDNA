import type { ProjectRepositoryProvider, ProviderRepository, RepositoryProviderConnection, RepositoryProviderImport } from "@/lib/api/types";
import { project } from "@/test/responses";

export const SHA = "a1b2c3d4e5f60718293a4b5c6d7e8f9012345678";

export function providerRepository(overrides: Partial<ProviderRepository> = {}): ProviderRepository {
  return { id: "4242", full_name: "acme/app", private: true, archived: false, default_branch: "main", ...overrides };
}

export function providerConnection(overrides: Partial<RepositoryProviderConnection> = {}): RepositoryProviderConnection {
  return {
    id: "01JA0000000000000000000010",
    type: "repository_provider_connection",
    project_id: project.id,
    provider: "gitlab",
    status: "ACTIVE",
    repository: providerRepository(),
    branch: "main",
    last_imported_commit_sha: null,
    last_imported_at: null,
    connected_at: "2026-10-09T09:00:00Z",
    disconnected_at: null,
    ...overrides,
  };
}

export function providerImport(overrides: Partial<RepositoryProviderImport> = {}): RepositoryProviderImport {
  return {
    id: "01JA0000000000000000000020",
    type: "repository_provider_import",
    project_id: project.id,
    provider: "gitlab",
    status: "SUCCEEDED",
    repository: "acme/app",
    ref: "main",
    commit_sha: SHA,
    failure_code: null,
    source_snapshot: { id: "01JA0000000000000000000030", version: 1 },
    created_snapshot: true,
    created_at: "2026-10-09T09:01:00Z",
    started_at: "2026-10-09T09:01:01Z",
    completed_at: "2026-10-09T09:01:05Z",
    ...overrides,
  };
}

export function projectProvider(overrides: Partial<ProjectRepositoryProvider> = {}, accounts: { gitlab?: boolean; bitbucket?: boolean; configured?: boolean } = {}): ProjectRepositoryProvider {
  const configured = accounts.configured ?? true;
  return {
    providers: [
      { provider: "gitlab", name: "GitLab", configured, account_connected: accounts.gitlab ?? false },
      { provider: "bitbucket", name: "Bitbucket Cloud", configured, account_connected: accounts.bitbucket ?? false },
    ],
    github_connected: false,
    connection: null,
    latest_import: null,
    ...overrides,
  };
}
