import { api } from "@/lib/api/client";
import type { AiAssessment, AiAssessmentResponse, AiAssessmentSummary, Paginated } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side access to a project's AI assessments (Phase 15). A request
 * only selects a stored skill gap snapshot: no prompt, model, score or
 * evidence is ever sent.
 */

function assessmentPath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/assessments${suffix}`;
}

/** Newest first. */
export function listAssessments(projectId: string, page = 1, perPage = 10): Promise<Paginated<AiAssessmentSummary>> {
  return api.get<Paginated<AiAssessmentSummary>>(assessmentPath(projectId, `?page=${page}&per_page=${perPage}`));
}

export async function getAssessment(projectId: string, assessmentId: string): Promise<AiAssessment> {
  if (!isProjectId(assessmentId)) {
    throw new Error("Invalid assessment ID.");
  }
  return (await api.get<AiAssessmentResponse>(assessmentPath(projectId, `/${assessmentId.toLowerCase()}`))).data;
}

/** Queues an interpretation of the newest skill gap analysis (or returns the existing one). */
export async function requestAssessment(projectId: string): Promise<AiAssessment> {
  return (await api.post<AiAssessmentResponse>(assessmentPath(projectId), {})).data;
}
