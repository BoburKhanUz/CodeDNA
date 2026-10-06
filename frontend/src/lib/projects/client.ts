import { api, upload } from "@/lib/api/client";
import type {
  AnalysisRun,
  CreateProjectRequest,
  Paginated,
  Project,
  ProjectResponse,
  SourceSnapshot,
  SourceSnapshotResponse,
} from "@/lib/api/types";

/** Browser-side project and source snapshot operations. */

const PROJECTS = "/api/v1/projects";

/** Project IDs are ULIDs; anything else is rejected before building a URL. */
function projectPath(projectId: string, suffix = ""): string {
  if (!/^[0-9a-z]{26}$/i.test(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `${PROJECTS}/${projectId.toLowerCase()}${suffix}`;
}

export function isProjectId(value: string): boolean {
  return /^[0-9a-z]{26}$/i.test(value);
}

export function listProjects(page = 1, perPage = 25): Promise<Paginated<Project>> {
  return api.get<Paginated<Project>>(`${PROJECTS}?page=${page}&per_page=${perPage}`);
}

export async function getProject(projectId: string): Promise<Project> {
  return (await api.get<ProjectResponse>(projectPath(projectId))).data;
}

export async function createProject(input: CreateProjectRequest): Promise<Project> {
  return (await api.post<ProjectResponse>(PROJECTS, input)).data;
}

/** ACTIVE → ARCHIVED. Irreversible; idempotent. */
export async function archiveProject(projectId: string): Promise<Project> {
  return (await api.post<ProjectResponse>(projectPath(projectId, "/archive"))).data;
}

export function listSourceSnapshots(projectId: string, page = 1, perPage = 25): Promise<Paginated<SourceSnapshot>> {
  return api.get<Paginated<SourceSnapshot>>(projectPath(projectId, `/source-snapshots?page=${page}&per_page=${perPage}`));
}

/**
 * Uploads a ZIP archive. Pass the same idempotency key when retrying the same
 * upload (e.g. after a network failure): the server then returns the
 * snapshot the first attempt created instead of creating another.
 */
export async function uploadSource(
  projectId: string,
  archive: File,
  options: { idempotencyKey?: string; onProgress?: (fraction: number) => void } = {},
): Promise<SourceSnapshot> {
  const form = new FormData();
  form.append("archive", archive);
  const response = await upload<SourceSnapshotResponse>(projectPath(projectId, "/source-snapshots"), form, {
    onProgress: options.onProgress,
    headers: options.idempotencyKey ? { "Idempotency-Key": options.idempotencyKey } : undefined,
  });
  return response.data;
}

/** Analysis runs, newest first (read-only here: runs are started through the API). */
export function listAnalyses(projectId: string, page = 1, perPage = 25): Promise<Paginated<AnalysisRun>> {
  return api.get<Paginated<AnalysisRun>>(projectPath(projectId, `/analyses?page=${page}&per_page=${perPage}`));
}
