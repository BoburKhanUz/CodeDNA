import { api } from "@/lib/api/client";
import type { AiInsight, AiStatus, DataEnvelope, InsightKind } from "@/lib/api/types";
import { INSIGHT_KINDS } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";

/**
 * Browser-side access to AI insights (Phase 29). A request names a kind and
 * the ID of a stored deterministic result: no prompt, model, URL, score or
 * evidence is ever sent, and nothing here interprets anything.
 */

const ULID = /^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/;

function path(projectId: string, suffix = ""): string {
  if (!isProjectId(projectId)) {
    throw new Error("Invalid project ID.");
  }
  return `/api/v1/projects/${projectId.toLowerCase()}/insights${suffix}`;
}

function checked(kind: InsightKind, subjectId: string): { kind: InsightKind; subject_id: string } {
  if (!(INSIGHT_KINDS as readonly string[]).includes(kind) || !ULID.test(subjectId)) {
    throw new Error("Invalid insight subject.");
  }
  return { kind, subject_id: subjectId.toLowerCase() };
}

export async function getAiStatus(): Promise<AiStatus> {
  return (await api.get<DataEnvelope<AiStatus>>("/api/v1/ai/status")).data;
}

/** The newest insights of one subject, newest first. */
export async function listInsights(projectId: string, kind: InsightKind, subjectId: string): Promise<AiInsight[]> {
  const subject = checked(kind, subjectId);
  return (await api.get<DataEnvelope<AiInsight[]>>(path(projectId, `?kind=${subject.kind}&subject_id=${subject.subject_id}`))).data;
}

export async function getInsight(projectId: string, insightId: string): Promise<AiInsight> {
  if (!ULID.test(insightId)) {
    throw new Error("Invalid insight ID.");
  }
  return (await api.get<DataEnvelope<AiInsight>>(path(projectId, `/${insightId.toLowerCase()}`))).data;
}

/** Queues an insight (or returns the existing one for the same evidence). */
export async function requestInsight(projectId: string, kind: InsightKind, subjectId: string): Promise<AiInsight> {
  return (await api.post<DataEnvelope<AiInsight>>(path(projectId), checked(kind, subjectId))).data;
}
