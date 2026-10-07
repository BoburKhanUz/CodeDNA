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
import type { FocusEntry, Project, Roadmap, RoadmapStep, RoadmapSummary, RoadmapTrack, SkillGapSnapshotSummary } from "@/lib/api/types";
import { challengeStatusLabel, competencyName, difficultyLabel } from "@/lib/challenge/format";
import { levelLabel } from "@/lib/competency/format";
import { formatPercent, formatScore } from "@/lib/dna/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";
import { completeStep, generateRoadmap, getRoadmap, listRoadmaps } from "@/lib/roadmap/client";
import { exclusionLabel, formatMinutes, rankExplanation, roadmapStatusLabel, stepTypeLabel } from "@/lib/roadmap/format";
import { listSkillGapSnapshots } from "@/lib/skill-gap/client";
import { priorityLabel } from "@/lib/skill-gap/format";

export const NOTICE =
  "Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.";

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "empty"; project: Project; newestGaps: SkillGapSnapshotSummary | null }
  | { status: "ready"; project: Project; roadmap: Roadmap; history: RoadmapSummary[]; newestGaps: SkillGapSnapshotSummary | null };

type Action = { status: "idle" | "sending" } | { status: "error"; error: unknown };

/**
 * The learning roadmap of a project (Phase 17): what to work on next,
 * generated deterministically from the newest skill gap analysis. A
 * planning layer: completing steps records learning progress only, and
 * nothing here changes or recomputes CodeDNA, competencies or gaps.
 */
export function RoadmapView({ projectId, roadmapId }: { projectId: string; roadmapId?: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId) && (roadmapId === undefined || isProjectId(roadmapId));
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const [action, setAction] = useState<Action>({ status: "idle" });

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
    fetchRoadmap(projectId, roadmapId).then(setState).catch(handleError);
  }, [projectId, roadmapId, valid, handleError]);

  useEffect(() => load(), [load]);

  const fail = useCallback(
    (error: unknown) => (isApiError(error) && error.status === 401 ? handleError(error) : setAction({ status: "error", error })),
    [handleError],
  );

  const generate = useCallback(() => {
    setAction({ status: "sending" });
    generateRoadmap(projectId)
      .then(() => {
        setAction({ status: "idle" });
        if (roadmapId !== undefined) {
          router.push(`/app/projects/${projectId}/roadmap`);
        }
        load();
      })
      .catch(fail);
  }, [projectId, roadmapId, router, load, fail]);

  const complete = useCallback(
    (roadmap: Roadmap, step: RoadmapStep) => {
      setAction({ status: "sending" });
      completeStep(projectId, roadmap.id, step.key)
        .then((updated) => {
          setAction({ status: "idle" });
          setState((current) =>
            current.status === "ready"
              ? {
                  ...current,
                  roadmap: updated,
                  history: current.history.map((h) => (h.id === updated.id ? { ...h, status: updated.status, progress: updated.progress } : h)),
                }
              : current,
          );
        })
        .catch(fail);
    },
    [projectId, fail],
  );

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading learning roadmap">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-24" />
        <Skeleton className="h-64" />
        <span className="sr-only">Loading learning roadmap…</span>
      </div>
    );
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{roadmapId === undefined ? "Project not found" : "Roadmap not found"}</h1>
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

  const { project, newestGaps } = state;
  const archived = project.status === "ARCHIVED";
  const sending = action.status === "sending";

  return (
    <div className="grid max-w-5xl gap-6">
      <div className="grid gap-2">
        <nav aria-label="Related pages" className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <Link href={`/app/projects/${project.id}`} className="text-muted-foreground underline-offset-4 hover:underline">
            ← {project.name}
          </Link>
          <Link href={`/app/projects/${project.id}/competencies`} className="text-muted-foreground underline-offset-4 hover:underline">
            Competency Matrix
          </Link>
          <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-muted-foreground underline-offset-4 hover:underline">
            Skill Gaps
          </Link>
          <Link href={`/app/projects/${project.id}/challenges`} className="text-muted-foreground underline-offset-4 hover:underline">
            Coding Challenges
          </Link>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Learning Roadmap</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl">
          What to work on next, chosen from the measurable skill gaps of {project.name}&apos;s newest analysis: the development focus,
          and short, ordered learning steps for each focus competency.
        </p>
        <p className="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950" data-testid="roadmap-notice">
          {NOTICE}
        </p>
      </div>

      {action.status === "error" ? <ApiErrorAlert error={action.error} /> : null}

      {state.status === "empty" ? (
        <EmptyState project={project} newestGaps={newestGaps} archived={archived} sending={sending} onGenerate={generate} />
      ) : (
        <RoadmapDetail
          project={project}
          roadmap={state.roadmap}
          history={state.history}
          newestGaps={newestGaps}
          archived={archived}
          sending={sending}
          onGenerate={generate}
          onComplete={complete}
        />
      )}
    </div>
  );
}

function EmptyState({
  project,
  newestGaps,
  archived,
  sending,
  onGenerate,
}: {
  project: Project;
  newestGaps: SkillGapSnapshotSummary | null;
  archived: boolean;
  sending: boolean;
  onGenerate: () => void;
}) {
  const [title, description] =
    newestGaps === null
      ? ["No roadmap available", "A roadmap is created from a skill gap analysis, which needs a completed static analysis of the project's source."]
      : newestGaps.status === "INSUFFICIENT_DATA"
        ? ["Not enough evidence", "The newest analysis could not measure any competency, so no learning need can be established. Analyze more source code first."]
        : newestGaps.status === "NO_MATERIAL_GAPS"
          ? ["No active development focus", "The newest analysis found no material gap against the target profile, so there is nothing to plan."]
          : ["No roadmap yet", "Create a roadmap from the measurable gaps of the newest skill gap analysis."];
  const canGenerate = newestGaps?.status === "GAPS_IDENTIFIED" && !archived;

  return (
    <Card data-testid="roadmap-empty">
      <CardHeader>
        <CardTitle>
          <h2>{title}</h2>
        </CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap items-center gap-4">
        {canGenerate ? (
          <Button onClick={onGenerate} disabled={sending} data-testid="roadmap-generate">
            {sending ? "Creating…" : "Create learning roadmap"}
          </Button>
        ) : null}
        <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-sm underline underline-offset-4">
          Open Skill Gaps
        </Link>
        {archived ? <p className="text-muted-foreground text-sm">This project is archived: no new roadmap can be created.</p> : null}
      </CardContent>
    </Card>
  );
}

function RoadmapDetail({
  project,
  roadmap,
  history,
  newestGaps,
  archived,
  sending,
  onGenerate,
  onComplete,
}: {
  project: Project;
  roadmap: Roadmap;
  history: RoadmapSummary[];
  newestGaps: SkillGapSnapshotSummary | null;
  archived: boolean;
  sending: boolean;
  onGenerate: () => void;
  onComplete: (roadmap: Roadmap, step: RoadmapStep) => void;
}) {
  const newest = history[0]?.id === roadmap.id;
  const newerAnalysis = newest && newestGaps !== null && newestGaps.id !== roadmap.skill_gap_snapshot_id;
  const editable = roadmap.status === "ACTIVE" && !archived;

  return (
    <div className="grid gap-6">
      {roadmap.status === "SUPERSEDED" ? (
        <div className="rounded-lg border p-3 text-sm" data-testid="roadmap-superseded">
          Superseded{roadmap.superseded_at ? ` on ${formatDateTime(roadmap.superseded_at)}` : ""}: a roadmap for a newer skill gap analysis
          replaced this one. It stays readable, and its progress is kept.{" "}
          <Link href={`/app/projects/${project.id}/roadmap`} className="underline underline-offset-4">
            Open the current roadmap
          </Link>
        </div>
      ) : null}
      {newerAnalysis ? (
        <div className="grid gap-2 rounded-lg border p-3 text-sm" data-testid="roadmap-newer-analysis">
          {newestGaps.status === "GAPS_IDENTIFIED" ? (
            <>
              <p>A newer skill gap analysis is available. Updating creates a new roadmap from it; this one is kept as superseded.</p>
              {!archived ? (
                <Button className="w-fit" variant="outline" onClick={onGenerate} disabled={sending} data-testid="roadmap-update">
                  {sending ? "Updating…" : "Update roadmap"}
                </Button>
              ) : null}
            </>
          ) : (
            <p>
              The newest skill gap analysis has no actionable gap
              {newestGaps.status === "INSUFFICIENT_DATA" ? " (not enough evidence)" : " (no material gaps)"}, so this roadmap stays as it is.
            </p>
          )}
        </div>
      ) : null}
      {!roadmap.current.catalog || !roadmap.current.rules ? (
        <p className="text-muted-foreground text-sm" data-testid="roadmap-version-note">
          This roadmap was generated with an earlier roadmap catalog or rules version. It stays exactly as it was generated.
        </p>
      ) : null}
      {archived ? (
        <p className="text-muted-foreground text-sm" data-testid="roadmap-archived">
          This project is archived: its roadmaps stay readable, but progress can no longer change.
        </p>
      ) : null}

      <Card data-testid="roadmap-progress">
        <CardHeader>
          <CardTitle>
            <h2>Learning progress</h2>
          </CardTitle>
          <CardDescription>
            Self-reported: steps you marked as done. This is not a CodeDNA assessment and not evidence of skill.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <p>
            <span className="font-semibold tabular-nums">
              {roadmap.progress.completed} of {roadmap.progress.total}
            </span>{" "}
            steps done · {roadmapStatusLabel(roadmap.status)} · about {formatMinutes(roadmap.estimated_minutes)} in total
          </p>
          <div
            className="bg-muted h-2 overflow-hidden rounded-full"
            role="progressbar"
            aria-label="Learning progress"
            aria-valuemin={0}
            aria-valuemax={roadmap.progress.total}
            aria-valuenow={roadmap.progress.completed}
          >
            <div className="bg-primary h-full" style={{ width: `${(100 * roadmap.progress.completed) / Math.max(roadmap.progress.total, 1)}%` }} />
          </div>
        </CardContent>
      </Card>

      <DevelopmentFocus roadmap={roadmap} />

      {roadmap.tracks.map((track) => (
        <TrackCard key={track.key} project={project} roadmap={roadmap} track={track} editable={editable} sending={sending} onComplete={onComplete} />
      ))}

      {history.length > 1 ? <History project={project} history={history} current={roadmap.id} /> : null}
      <Provenance project={project} roadmap={roadmap} />
    </div>
  );
}

function DevelopmentFocus({ roadmap }: { roadmap: Roadmap }) {
  const { selected, excluded } = roadmap.development_focus;

  return (
    <Card data-testid="development-focus">
      <CardHeader>
        <CardTitle>
          <h2>Development focus</h2>
        </CardTitle>
        <CardDescription>
          The measurable gaps of the skill gap analysis, in order: higher priority first, then the larger gap, then better evidence
          quality. Values are as measured when this roadmap was created; scores and gaps are out of 100.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <ol className="grid gap-3">
          {selected.map((entry) => (
            <FocusItem key={entry.competency_key} entry={entry} />
          ))}
        </ol>
        {excluded.length > 0 ? (
          <div className="grid gap-1 text-sm" data-testid="focus-excluded">
            <h3 className="font-medium">Not in this roadmap</h3>
            <ul className="text-muted-foreground grid gap-1">
              {excluded.map((entry) => (
                <li key={entry.competency_key} data-testid={`excluded-${entry.competency_key}`}>
                  {competencyName(entry.competency_key)}: {exclusionLabel(entry.reason)}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

function FocusItem({ entry }: { entry: FocusEntry }) {
  return (
    <li className="grid gap-1 rounded-lg border p-3 text-sm" data-testid={`focus-${entry.competency_key}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">
          {entry.rank}. {competencyName(entry.competency_key)}
        </span>
        <span className="bg-muted inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium">{priorityLabel(entry.priority)}</span>
      </div>
      <p>
        Current {formatScore(entry.current_score) ?? "not measured"}
        {entry.current_level ? ` (${levelLabel(entry.current_level)})` : ""} · target {formatScore(entry.target_score) ?? "none"} · gap{" "}
        <span className="font-semibold tabular-nums">{formatScore(entry.raw_gap)} points</span> · evidence quality{" "}
        {formatPercent(entry.evidence_quality) ?? "not available"}
        {entry.priority_capped ? " · priority capped at medium: limited evidence" : ""}
      </p>
      <p className="text-muted-foreground">{rankExplanation(entry.rank, entry.deciding_criterion, entry.ranked_above)}</p>
    </li>
  );
}

function TrackCard({
  project,
  roadmap,
  track,
  editable,
  sending,
  onComplete,
}: {
  project: Project;
  roadmap: Roadmap;
  track: RoadmapTrack;
  editable: boolean;
  sending: boolean;
  onComplete: (roadmap: Roadmap, step: RoadmapStep) => void;
}) {
  return (
    <Card data-testid={`track-${track.key}`}>
      <CardHeader>
        <CardTitle>
          <h2>
            {track.position}. {track.title}
          </h2>
        </CardTitle>
        <CardDescription>
          {competencyName(track.competency_key)} · {track.progress.completed} of {track.progress.total} steps done · about{" "}
          {formatMinutes(track.estimated_minutes)}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        <p className="text-sm">{track.description}</p>
        <p className="text-sm">
          <span className="font-medium">Objective:</span> {track.objective}
        </p>
        <ol className="grid gap-2" aria-label={`Steps of ${track.title}`}>
          {track.steps.map((step) => (
            <StepItem key={step.key} project={project} roadmap={roadmap} step={step} editable={editable} sending={sending} onComplete={onComplete} />
          ))}
        </ol>
      </CardContent>
    </Card>
  );
}

function StepItem({
  project,
  roadmap,
  step,
  editable,
  sending,
  onComplete,
}: {
  project: Project;
  roadmap: Roadmap;
  step: RoadmapStep;
  editable: boolean;
  sending: boolean;
  onComplete: (roadmap: Roadmap, step: RoadmapStep) => void;
}) {
  const done = step.completed_at !== null;

  return (
    <li className={`grid gap-1 rounded-lg border p-3 text-sm ${done ? "bg-muted/40" : ""}`} data-testid={`step-${step.key}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="bg-muted inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium">{stepTypeLabel(step.type)}</span>
        <span className="font-medium">{step.title}</span>
        <span className="text-muted-foreground text-xs">about {formatMinutes(step.estimated_minutes)}</span>
      </div>
      <p>{step.description}</p>
      <p className="text-muted-foreground">Goal: {step.objective}</p>
      {step.type === "CHALLENGE" ? <ChallengePractice project={project} step={step} /> : null}
      {step.type === "REASSESS" ? (
        <p>
          <Link href={`/app/projects/${project.id}`} className="underline underline-offset-4">
            Go to the project to upload changed source and run a new analysis
          </Link>{" "}
          <span className="text-muted-foreground">(nothing is started automatically).</span>
        </p>
      ) : null}
      <div className="flex flex-wrap items-center gap-3 pt-1" data-testid="step-state">
        {done ? (
          <span>✓ Done {step.completed_at ? `on ${formatDateTime(step.completed_at)}` : ""}</span>
        ) : editable && step.can_complete ? (
          <Button size="sm" variant="outline" onClick={() => onComplete(roadmap, step)} disabled={sending}>
            Mark as done
          </Button>
        ) : editable ? (
          <span className="text-muted-foreground">Complete the earlier steps it depends on first.</span>
        ) : (
          <span className="text-muted-foreground">Not done</span>
        )}
      </div>
    </li>
  );
}

function ChallengePractice({ project, step }: { project: Project; step: RoadmapStep }) {
  if (step.challenge === null) {
    return <p className="text-muted-foreground">No matching challenge is available for this competency.</p>;
  }
  const challenge = step.challenge;

  return (
    <div className="grid gap-1" data-testid="step-challenge">
      <p>
        Recommended challenge: <span className="font-medium">{challenge.title}</span> ({difficultyLabel(challenge.difficulty)})
        {!challenge.in_catalog ? " · no longer in the challenge catalog" : ""}
      </p>
      {step.practice !== null ? (
        <Link href={`/app/projects/${project.id}/challenges/${step.practice.challenge_id}`} className="w-fit underline underline-offset-4">
          Open your challenge ({challengeStatusLabel(step.practice.status)})
        </Link>
      ) : (
        <Link href={`/app/projects/${project.id}/challenges`} className="w-fit underline underline-offset-4">
          Open Coding Challenges
        </Link>
      )}
      <p className="text-muted-foreground text-xs">Passing a challenge is practice: it does not close the skill gap.</p>
    </div>
  );
}

function History({ project, history, current }: { project: Project; history: RoadmapSummary[]; current: string }) {
  return (
    <Card data-testid="roadmap-history">
      <CardHeader>
        <CardTitle>
          <h2>Roadmap history</h2>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="grid gap-1 text-sm">
          {history.map((item, i) => (
            <li key={item.id}>
              {item.id === current ? (
                <span className="font-medium">{formatDateTime(item.created_at)} (shown)</span>
              ) : (
                <Link
                  href={i === 0 ? `/app/projects/${project.id}/roadmap` : `/app/projects/${project.id}/roadmap?roadmap=${item.id}`}
                  className="underline underline-offset-4"
                >
                  {formatDateTime(item.created_at)}
                </Link>
              )}{" "}
              · {roadmapStatusLabel(item.status)} · {item.progress.completed} of {item.progress.total} steps ·{" "}
              {item.focus.map(competencyName).join(", ")}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function Provenance({ project, roadmap }: { project: Project; roadmap: Roadmap }) {
  return (
    <Card data-testid="roadmap-provenance">
      <CardHeader>
        <CardTitle>
          <h2>Provenance</h2>
        </CardTitle>
        <CardDescription>Created {formatDateTime(roadmap.created_at)} from these stored results; regenerating from them gives the same roadmap.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-1 text-sm">
        <p>
          <Link href={`/app/projects/${project.id}/skill-gaps`} className="underline underline-offset-4">
            Skill gap analysis
          </Link>{" "}
          · <Link href={`/app/projects/${project.id}/competencies`} className="underline underline-offset-4">Competency matrix</Link> ·{" "}
          <Link href={`/app/projects/${project.id}/dna/${roadmap.lineage.dna_snapshot_id}`} className="underline underline-offset-4">
            CodeDNA assessment
          </Link>
        </p>
        <p className="text-muted-foreground">
          Roadmap {roadmap.versions.roadmap} · rules {roadmap.versions.rules} · skill gap {roadmap.versions.skill_gap} · target profile{" "}
          {roadmap.versions.target_profile.key} {roadmap.versions.target_profile.version} · challenge catalog {roadmap.versions.challenge_catalog}
        </p>
        <p className="text-muted-foreground font-mono text-xs break-all">Roadmap fingerprint {roadmap.fingerprints.roadmap}</p>
      </CardContent>
    </Card>
  );
}

async function fetchRoadmap(projectId: string, roadmapId: string | undefined): Promise<State> {
  const [project, roadmaps, gaps] = await Promise.all([getProject(projectId), listRoadmaps(projectId, 1, 10), listSkillGapSnapshots(projectId, 1, 1)]);
  const newestGaps = gaps.data[0] ?? null;
  const id = roadmapId ?? roadmaps.data[0]?.id;
  if (id === undefined) {
    return { status: "empty", project, newestGaps };
  }
  const roadmap = await getRoadmap(projectId, id);
  return { status: "ready", project, roadmap, history: roadmaps.data, newestGaps };
}
