"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { ChallengeSummary, Project } from "@/lib/api/types";
import { assignChallenge, listChallenges } from "@/lib/challenge/client";
import { challengeStatusLabel, competencyName, difficultyLabel } from "@/lib/challenge/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";
import { listSkillGapSnapshots } from "@/lib/skill-gap/client";

export const NOTICE =
  "Completing a challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.";

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "loaded"; project: Project; challenges: ChallengeSummary[]; hasSkillGaps: boolean };

/**
 * Coding challenges of a project (Phase 16): exercises selected from the
 * newest skill gap analysis. A practice layer: it shows what the API
 * returns and never changes or recomputes any result.
 */
export function ChallengeList({ projectId }: { projectId: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const [assigning, setAssigning] = useState<{ status: "idle" | "sending" } | { status: "error"; error: unknown }>({ status: "idle" });

  const handleError = useCallback(
    (error: unknown) => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      setState(isApiError(error) && error.status === 404 ? { status: "not-found" } : { status: "error", error });
    },
    [router],
  );

  const load = useCallback(() => {
    if (!valid) return;
    fetchChallenges(projectId).then(setState).catch(handleError);
  }, [projectId, valid, handleError]);

  useEffect(() => load(), [load]);

  const assign = useCallback(() => {
    setAssigning({ status: "sending" });
    assignChallenge(projectId)
      .then((challenge) => router.push(`/app/projects/${projectId}/challenges/${challenge.id}`))
      .catch((error: unknown) => (isApiError(error) && error.status === 401 ? handleError(error) : setAssigning({ status: "error", error })));
  }, [projectId, router, handleError]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading challenges">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-24" />
        <Skeleton className="h-48" />
        <span className="sr-only">Loading challenges…</span>
      </div>
    );
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">Project not found</h1>
        <p className="text-muted-foreground">It does not exist, or it belongs to another account.</p>
        <Link href="/app/projects" className="underline underline-offset-4">
          Back to projects
        </Link>
      </div>
    );
  }

  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <ApiErrorAlert error={state.error} />
        <Button variant="outline" className="w-fit" onClick={() => (setState({ status: "loading" }), load())}>
          Try again
        </Button>
      </div>
    );
  }

  const { project, challenges, hasSkillGaps } = state;
  const archived = project.status === "ARCHIVED";

  return (
    <div className="grid max-w-5xl gap-6">
      <div className="grid gap-2">
        <nav aria-label="Related pages" className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <Link href={`/app/projects/${project.id}`} className="text-muted-foreground underline-offset-4 hover:underline">
            ← {project.name}
          </Link>
          <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-muted-foreground underline-offset-4 hover:underline">
            Skill Gaps
          </Link>
          <Link href={`/app/projects/${project.id}/roadmap`} className="text-muted-foreground underline-offset-4 hover:underline">
            Learning Roadmap
          </Link>
          <Link href={`/app/projects/${project.id}/growth`} className="text-muted-foreground underline-offset-4 hover:underline">
            Growth
          </Link>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Coding Challenges</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl">
          Short, self-contained exercises chosen from the measurable skill gaps of {project.name}&apos;s newest analysis. Each one
          is checked by deterministic tests and code-structure rules in an isolated sandbox.
        </p>
        <p className="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950" data-testid="challenge-notice">
          {NOTICE}
        </p>
      </div>

      {assigning.status === "error" ? <ApiErrorAlert error={assigning.error} /> : null}

      {challenges.length === 0 ? (
        <Card data-testid="challenges-empty">
          <CardHeader>
            <CardTitle>
              <h2>No challenges yet</h2>
            </CardTitle>
            <CardDescription>
              {hasSkillGaps
                ? "Get an exercise for the highest-priority skill gap of the newest analysis."
                : "Challenges are selected from a skill gap analysis, which needs a completed static analysis of the project's source."}
            </CardDescription>
          </CardHeader>
          {!hasSkillGaps ? (
            <CardContent>
              <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-sm underline underline-offset-4">
                Open Skill Gaps
              </Link>
            </CardContent>
          ) : null}
        </Card>
      ) : (
        <ul className="grid gap-3" aria-label="Challenges">
          {challenges.map((challenge) => (
            <li key={challenge.id} data-testid={`challenge-${challenge.id}`}>
              <Link
                href={`/app/projects/${project.id}/challenges/${challenge.id}`}
                className="hover:bg-muted/50 grid gap-2 rounded-xl border p-4 transition-colors"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{challenge.definition.title ?? challenge.definition.key}</span>
                  <span className="bg-muted inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" data-testid="challenge-status">
                    {challengeStatusLabel(challenge.status)}
                  </span>
                </div>
                <span className="text-muted-foreground text-sm">
                  {competencyName(challenge.competency_key)} · {difficultyLabel(challenge.difficulty)} · {challenge.language} · attempts{" "}
                  {challenge.attempts_used} of {challenge.max_attempts}
                  {challenge.last_result ? ` · last result: ${challenge.last_result.toLowerCase()}` : ""} · assigned{" "}
                  {formatDateTime(challenge.created_at)}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {hasSkillGaps && !archived ? (
        <Button className="w-fit" onClick={assign} disabled={assigning.status === "sending"} data-testid="challenge-assign">
          {assigning.status === "sending" ? "Selecting…" : challenges.length === 0 ? "Get a challenge" : "Get the next challenge"}
        </Button>
      ) : null}
      {archived ? <p className="text-muted-foreground text-sm">This project is archived: its challenges stay readable, but no new ones can be started.</p> : null}
    </div>
  );
}

async function fetchChallenges(projectId: string): Promise<State> {
  const [project, challenges, gaps] = await Promise.all([getProject(projectId), listChallenges(projectId), listSkillGapSnapshots(projectId, 1, 1)]);
  return { status: "loaded", project, challenges: challenges.data, hasSkillGaps: gaps.data.length > 0 };
}
