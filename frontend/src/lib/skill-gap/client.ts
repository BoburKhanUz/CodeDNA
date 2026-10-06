import { api } from "@/lib/api/client";
import type { Paginated, SkillGapSnapshot, SkillGapSnapshotResponse, SkillGapSnapshotSummary } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/** Browser-side, read-only access to a project's skill gap snapshots (Phase 14). No targets are ever sent. */

function skillGapPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/skill-gaps${suffix}`;
}

/** Newest first. */
export function listSkillGapSnapshots(projectId: string, page = 1, perPage = 10): Promise<Paginated<SkillGapSnapshotSummary>> {
  return api.get<Paginated<SkillGapSnapshotSummary>>(skillGapPath(projectId, `?page=${page}&per_page=${perPage}`));
}

export async function getSkillGapSnapshot(projectId: string, snapshotId: string): Promise<SkillGapSnapshot> {
  if (!isProjectId(snapshotId)) {
    throw new Error("Invalid skill gap snapshot ID.");
  }
  return (await api.get<SkillGapSnapshotResponse>(skillGapPath(projectId, `/${snapshotId.toLowerCase()}`))).data;
}
