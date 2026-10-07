import { api } from "@/lib/api/client";
import type {
  Challenge,
  ChallengeResponse,
  ChallengeSubmission,
  ChallengeSubmissionResponse,
  ChallengeSubmissionSummary,
  ChallengeSummary,
  Paginated,
} from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side access to a project's coding challenges (Phase 16). Assigning
 * only selects a stored skill gap; submitting sends one file of source. No
 * definition, test, command or runtime is ever sent.
 */

function challengePath(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/challenges${suffix}`;
}

function id(value: string, what: string): string {
  if (!isProjectId(value)) {
    throw new Error(`Invalid ${what} ID.`);
  }
  return value.toLowerCase();
}

/** Newest first. */
export function listChallenges(projectId: string, page = 1, perPage = 20): Promise<Paginated<ChallengeSummary>> {
  return api.get<Paginated<ChallengeSummary>>(challengePath(projectId, `?page=${page}&per_page=${perPage}`));
}

export async function getChallenge(projectId: string, challengeId: string): Promise<Challenge> {
  return (await api.get<ChallengeResponse>(challengePath(projectId, `/${id(challengeId, "challenge")}`))).data;
}

/** Assigns a challenge for the newest skill gap analysis (or returns the active one). */
export async function assignChallenge(projectId: string): Promise<Challenge> {
  return (await api.post<ChallengeResponse>(challengePath(projectId), {})).data;
}

export function listSubmissions(projectId: string, challengeId: string, page = 1, perPage = 20): Promise<Paginated<ChallengeSubmissionSummary>> {
  return api.get<Paginated<ChallengeSubmissionSummary>>(challengePath(projectId, `/${id(challengeId, "challenge")}/submissions?page=${page}&per_page=${perPage}`));
}

export async function getSubmission(projectId: string, challengeId: string, submissionId: string): Promise<ChallengeSubmission> {
  return (
    await api.get<ChallengeSubmissionResponse>(
      challengePath(projectId, `/${id(challengeId, "challenge")}/submissions/${id(submissionId, "submission")}`),
    )
  ).data;
}

export async function submitSolution(projectId: string, challengeId: string, language: string, source: string): Promise<ChallengeSubmission> {
  return (
    await api.post<ChallengeSubmissionResponse>(challengePath(projectId, `/${id(challengeId, "challenge")}/submissions`), { language, source })
  ).data;
}
