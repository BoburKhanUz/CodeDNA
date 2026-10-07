import { api } from "@/lib/api/client";
import type { GrowthOverview, GrowthOverviewResponse, GrowthSnapshot, GrowthSnapshotResponse, GrowthSnapshotSummary, Paginated } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side, read-only access to a project's growth (Phase 18). There is
 * nothing to send: growth is calculated by the server from code
 * assessments only.
 */

function growthPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/growth${suffix}`;
}

export async function getGrowth(projectId: string): Promise<GrowthOverview> {
  return (await api.get<GrowthOverviewResponse>(growthPath(projectId))).data;
}

/** Newest assessment first. */
export function getGrowthTimeline(projectId: string, page = 1, perPage = 10): Promise<Paginated<GrowthSnapshotSummary>> {
  return api.get<Paginated<GrowthSnapshotSummary>>(growthPath(projectId, `/timeline?page=${page}&per_page=${perPage}`));
}

export async function getGrowthSnapshot(projectId: string, id: string): Promise<GrowthSnapshot> {
  if (!isProjectId(id)) {
    throw new Error("Invalid growth snapshot ID.");
  }
  return (await api.get<GrowthSnapshotResponse>(growthPath(projectId, `/${id.toLowerCase()}`))).data;
}
