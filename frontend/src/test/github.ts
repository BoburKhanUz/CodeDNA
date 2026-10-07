import type { GitHubConnection, GitHubImport, GitHubRepository, ProjectGitHub } from "@/lib/api/types";
import { project } from "@/test/responses";

/** GitHub responses shaped exactly like the Laravel API's (Phase 19). */

export const IMPORT_ID = "01k6s0a1b2c3d4e5f6g7h8j9im";
export const SNAPSHOT_ID = "01k6s0a1b2c3d4e5f6g7h8j9sn";
export const SHA = "6dcb09b5b57875f334f61aebed695e2e4193db5e";

export function repository(overrides: Partial<GitHubRepository> = {}): GitHubRepository {
  return { id: 1296269, owner: "octo-org", name: "billing-service", full_name: "octo-org/billing-service", private: true, archived: false, default_branch: "main", ...overrides };
}

export function connection(overrides: Partial<GitHubConnection> = {}): GitHubConnection {
  return {
    id: "01k6s0a1b2c3d4e5f6g7h8j9cn",
    type: "github_connection",
    project_id: project.id,
    status: "ACTIVE",
    repository: repository(),
    branch: "main",
    last_imported_commit_sha: null,
    last_imported_at: null,
    metadata_verified_at: "2026-10-16T09:00:00Z",
    connected_at: "2026-10-16T09:00:00Z",
    disconnected_at: null,
    ...overrides,
  };
}

export function githubImport(overrides: Partial<GitHubImport> = {}): GitHubImport {
  return {
    id: IMPORT_ID,
    type: "github_import",
    project_id: project.id,
    status: "SUCCEEDED",
    repository: "octo-org/billing-service",
    ref: "main",
    commit_sha: SHA,
    failure_code: null,
    source_snapshot: { id: SNAPSHOT_ID, version: 3 },
    created_snapshot: true,
    created_at: "2026-10-16T09:05:00Z",
    started_at: "2026-10-16T09:05:01Z",
    completed_at: "2026-10-16T09:05:09Z",
    ...overrides,
  };
}

export function projectGitHub(overrides: Partial<ProjectGitHub> = {}): ProjectGitHub {
  return { configured: true, account_connected: true, connection: null, latest_import: null, ...overrides };
}
