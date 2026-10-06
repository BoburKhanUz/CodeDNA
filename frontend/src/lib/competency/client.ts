import { api } from "@/lib/api/client";
import type { CompetencySnapshot, CompetencySnapshotResponse, CompetencySnapshotSummary, Paginated } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/** Browser-side, read-only access to a project's competency snapshots (Phase 13). There is no write operation. */

function competencyPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/competencies${suffix}`;
}

/** Newest first. */
export function listCompetencySnapshots(projectId: string, page = 1, perPage = 10): Promise<Paginated<CompetencySnapshotSummary>> {
  return api.get<Paginated<CompetencySnapshotSummary>>(competencyPath(projectId, `?page=${page}&per_page=${perPage}`));
}

export async function getCompetencySnapshot(projectId: string, snapshotId: string): Promise<CompetencySnapshot> {
  if (!isProjectId(snapshotId)) {
    throw new Error("Invalid competency snapshot ID.");
  }
  return (await api.get<CompetencySnapshotResponse>(competencyPath(projectId, `/${snapshotId.toLowerCase()}`))).data;
}
