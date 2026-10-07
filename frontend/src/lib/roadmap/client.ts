import { api } from "@/lib/api/client";
import type { Paginated, Roadmap, RoadmapResponse, RoadmapSummary } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side access to a project's learning roadmaps (Phase 17).
 * Generating sends nothing: tracks, steps, targets and versions are all
 * server-owned. Completing a step sends nothing but the step in the path.
 */

const STEP_KEY = /^(cm|fd|ts|ch)-[a-z0-9]+(-[a-z0-9]+)*$/;

function roadmapPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/roadmaps${suffix}`;
}

function roadmapId(value: string): string {
  if (!isProjectId(value)) {
    throw new Error("Invalid roadmap ID.");
  }
  return value.toLowerCase();
}

/** Newest first. */
export function listRoadmaps(projectId: string, page = 1, perPage = 10): Promise<Paginated<RoadmapSummary>> {
  return api.get<Paginated<RoadmapSummary>>(roadmapPath(projectId, `?page=${page}&per_page=${perPage}`));
}

export async function getRoadmap(projectId: string, id: string): Promise<Roadmap> {
  return (await api.get<RoadmapResponse>(roadmapPath(projectId, `/${roadmapId(id)}`))).data;
}

/** Generates the roadmap of the newest skill gap analysis (or returns the existing one). */
export async function generateRoadmap(projectId: string): Promise<Roadmap> {
  return (await api.post<RoadmapResponse>(roadmapPath(projectId), {})).data;
}

/** Marks one step as completed: learning progress only. */
export async function completeStep(projectId: string, id: string, stepKey: string): Promise<Roadmap> {
  if (!STEP_KEY.test(stepKey)) {
    throw new Error("Invalid step.");
  }
  return (await api.post<RoadmapResponse>(roadmapPath(projectId, `/${roadmapId(id)}/steps/${stepKey}/complete`), {})).data;
}
