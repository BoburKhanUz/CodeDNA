import { api } from "@/lib/api/client";
import type { HistoryComparison, HistoryComparisonResponse, HistoryPoint, HistoryPointDetail, HistoryPointResponse, Paginated } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side, read-only access to a project's historical DNA (Phase 20).
 * The only things sent are a page and the IDs of two points to compare:
 * values, versions and statuses always come from the server.
 */

function historyPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/history${suffix}`;
}

function snapshotId(id: string): string {
  if (!isProjectId(id)) {
    throw new Error("Invalid assessment ID.");
  }
  return id.toLowerCase();
}

/** Newest assessment first; at most 100 per page. */
export function getHistory(projectId: string, page = 1, perPage = 25): Promise<Paginated<HistoryPoint>> {
  const safePage = Math.max(1, Math.trunc(page));
  const safePerPage = Math.min(100, Math.max(1, Math.trunc(perPage)));
  return api.get<Paginated<HistoryPoint>>(historyPath(projectId, `?page=${safePage}&per_page=${safePerPage}`));
}

export async function getHistoryPoint(projectId: string, id: string): Promise<HistoryPointDetail> {
  return (await api.get<HistoryPointResponse>(historyPath(projectId, `/${snapshotId(id)}`))).data;
}

/** The server orders the two points by time and checks they are comparable. */
export async function compareHistory(projectId: string, from: string, to: string): Promise<HistoryComparison> {
  const query = `?from=${snapshotId(from)}&to=${snapshotId(to)}`;
  return (await api.get<HistoryComparisonResponse>(historyPath(projectId, `/compare${query}`))).data;
}
