import { api } from "@/lib/api/client";
import type { DnaSnapshot, DnaSnapshotResponse, DnaSnapshotSummary, Paginated } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/** Browser-side, read-only access to a project's DNA snapshots (Phase 12). There is no write operation. */

function dnaPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/dna${suffix}`;
}

/** DNA snapshot IDs are ULIDs, like project IDs. */
export function isDnaSnapshotId(value: string): boolean {
  return isProjectId(value);
}

/** Newest first. */
export function listDnaSnapshots(projectId: string, page = 1, perPage = 10): Promise<Paginated<DnaSnapshotSummary>> {
  return api.get<Paginated<DnaSnapshotSummary>>(dnaPath(projectId, `?page=${page}&per_page=${perPage}`));
}

export async function getDnaSnapshot(projectId: string, snapshotId: string): Promise<DnaSnapshot> {
  if (!isDnaSnapshotId(snapshotId)) {
    throw new Error("Invalid DNA snapshot ID.");
  }
  return (await api.get<DnaSnapshotResponse>(dnaPath(projectId, `/${snapshotId.toLowerCase()}`))).data;
}
