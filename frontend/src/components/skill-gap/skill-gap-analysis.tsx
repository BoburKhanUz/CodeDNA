"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { ScoreBar } from "@/components/dna/score-bar";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import { GAP_PRIORITIES, type Project, type SkillGapResult, type SkillGapSnapshot } from "@/lib/api/types";
import { listCompetencySnapshots } from "@/lib/competency/client";
import { levelLabel } from "@/lib/competency/format";
import { componentLabel, evidenceStatusLabel, formatPercent, formatScore } from "@/lib/dna/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime, languageLabel } from "@/lib/projects/format";
import { getSkillGapSnapshot, listSkillGapSnapshots } from "@/lib/skill-gap/client";
import { priorityLabel, skillGapStatusLabel, targetProfileLabel, unmeasuredExplanation } from "@/lib/skill-gap/format";

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "empty"; project: Project; hasCompetencies: boolean }
  | { status: "ready"; project: Project; snapshot: SkillGapSnapshot };

/**
 * Skill gaps of a project (Phase 14): the newest skill gap snapshot, which
 * compares the competency matrix with a versioned, server-owned target
 * profile. Read-only; it renders the API's values and computes none.
 */
export function SkillGapAnalysis({ projectId }: { projectId: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));

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
    fetchSkillGaps(projectId).then(setState).catch(handleError);
  }, [projectId, valid, handleError]);

  useEffect(() => load(), [load]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-6xl gap-6" role="status" aria-label="Loading skill gaps">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-24" />
        <div className="grid gap-4 md:grid-cols-2">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-56" />
          ))}
        </div>
        <span className="sr-only">Loading skill gaps…</span>
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
        <Button
          variant="outline"
          className="w-fit"
          onClick={() => {
            setState({ status: "loading" });
            load();
          }}
        >
          Try again
        </Button>
      </div>
    );
  }

  const { project } = state;

  return (
    <div className="grid max-w-6xl gap-6">
      <div className="grid gap-2">
        <nav aria-label="Related pages" className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <Link href={`/app/projects/${project.id}`} className="text-muted-foreground underline-offset-4 hover:underline">
            ← {project.name}
          </Link>
          <Link href={`/app/projects/${project.id}/dna`} className="text-muted-foreground underline-offset-4 hover:underline">
            CodeDNA
          </Link>
          <Link href={`/app/projects/${project.id}/competencies`} className="text-muted-foreground underline-offset-4 hover:underline">
            Competency Matrix
          </Link>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Skill Gaps</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl">
          Measurable differences between the competency evidence in {project.name}&apos;s analyzed source code and a
          versioned engineering target. A gap represents the difference between the current measured competency score and
          the configured target.
        </p>
      </div>

      {state.status === "empty" ? (
        <Card data-testid="skill-gap-empty">
          <CardHeader>
            <CardTitle>
              <h2>No skill gap analysis is available yet.</h2>
            </CardTitle>
            <CardDescription>
              {state.hasCompetencies
                ? "A competency matrix exists, but no skill gap analysis has been derived from it yet."
                : "A skill gap analysis is derived from the competency matrix, which needs a completed static analysis of the project's source."}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Link href={`/app/projects/${project.id}/competencies`} className="text-sm underline underline-offset-4">
              Open Competency Matrix
            </Link>
          </CardContent>
        </Card>
      ) : (
        <Report snapshot={state.snapshot} projectId={project.id} />
      )}

      <p className="text-muted-foreground max-w-3xl text-xs">
        Skill Gap results describe measurable differences between observed source-code competency evidence and a versioned
        target definition. They do not establish developer seniority, intelligence, personality, professional worth, or
        future potential.
      </p>
    </div>
  );
}

async function fetchSkillGaps(projectId: string): Promise<State> {
  const [project, list] = await Promise.all([getProject(projectId), listSkillGapSnapshots(projectId, 1, 1)]);
  const latest = list.data[0];
  if (latest === undefined) {
    const competencies = await listCompetencySnapshots(projectId, 1, 1);
    return { status: "empty", project, hasCompetencies: competencies.meta.total > 0 };
  }
  return { status: "ready", project, snapshot: await getSkillGapSnapshot(projectId, latest.id) };
}

function Report({ snapshot, projectId }: { snapshot: SkillGapSnapshot; projectId: string }) {
  const { summary, thresholds } = snapshot;

  return (
    <div className="grid gap-6">
      <Card data-testid="skill-gap-target">
        <CardHeader>
          <CardTitle>
            <h2>
              Target profile: {targetProfileLabel(snapshot.target_profile.key)} {snapshot.target_profile.version}
            </h2>
          </CardTitle>
          <CardDescription>
            {snapshot.target_profile.description} The target values are product calibration choices and are not presented as
            empirical industry standards.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 text-sm">
          <p data-testid="skill-gap-summary">
            <span className="font-medium">
              {summary.material_gaps} material {summary.material_gaps === 1 ? "gap" : "gaps"}
            </span>
            {GAP_PRIORITIES.map((priority) => ` · ${priorityLabel(priority)}: ${summary.priorities[priority]}`).join("")}
          </p>
          {thresholds ? (
            <p className="text-muted-foreground text-xs">
              Scores and gaps are shown out of 100. A gap is material from {formatScore(thresholds.material_gap)} points.
              Priority: {thresholds.priorities.map((p) => `${priorityLabel(p.priority)} from ${formatScore(p.minimum_gap)}`).join(", ")}
              ; high priority needs evidence quality of at least {formatPercent(thresholds.high_priority_minimum_evidence_quality)}.
            </p>
          ) : null}
          <p className="text-muted-foreground text-xs">
            Skill gap version {snapshot.skill_gap_version} · calculated {formatDateTime(snapshot.created_at)}
            {snapshot.source_snapshot ? ` · source snapshot v${snapshot.source_snapshot.version}` : ""}
          </p>
        </CardContent>
      </Card>

      {snapshot.status === "NO_MATERIAL_GAPS" ? (
        <Card data-testid="skill-gap-none">
          <CardHeader>
            <CardTitle>
              <h2>No material competency gaps were identified against the selected engineering standard.</h2>
            </CardTitle>
            <CardDescription>Competencies that could not be measured are listed below with their reason.</CardDescription>
          </CardHeader>
        </Card>
      ) : null}
      {snapshot.status === "INSUFFICIENT_DATA" ? (
        <Card data-testid="skill-gap-insufficient">
          <CardHeader>
            <CardTitle>
              <h2>Insufficient evidence</h2>
            </CardTitle>
            <CardDescription>
              No targeted competency could be measured, so no gap can be determined. This is not the same as having no gaps.
            </CardDescription>
          </CardHeader>
        </Card>
      ) : null}

      <div className="grid items-start gap-4 md:grid-cols-2">
        {snapshot.results.map((result) => (
          <GapCard key={result.competency_key} result={result} />
        ))}
      </div>

      <Card data-testid="challenge-link">
        <CardHeader>
          <CardTitle>
            <h2>Coding Challenges</h2>
          </CardTitle>
          <CardDescription>
            Practise on short exercises selected from these gaps, checked by deterministic tests. Completing a challenge does not
            immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Link href={`/app/projects/${projectId}/challenges`} className="text-sm font-medium underline underline-offset-4">
            View Coding Challenges →
          </Link>
        </CardContent>
      </Card>

      <Card data-testid="assessment-link">
        <CardHeader>
          <CardTitle>
            <h2>AI Assessment</h2>
          </CardTitle>
          <CardDescription>
            An AI-generated, plain-language interpretation of these results, with the evidence behind every statement. It does not
            change any score, gap or priority.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Link href={`/app/projects/${projectId}/assessment`} className="text-sm font-medium underline underline-offset-4">
            View AI Assessment →
          </Link>
        </CardContent>
      </Card>

      <Provenance snapshot={snapshot} projectId={projectId} />
    </div>
  );
}

function GapCard({ result }: { result: SkillGapResult }) {
  const measured = result.status === "GAP" || result.status === "NO_GAP";
  const priority = result.status === "GAP" ? priorityLabel(result.priority) : null;

  return (
    <Card className="gap-4" data-testid={`skill-gap-${result.competency_key}`}>
      <CardHeader>
        <CardTitle>
          <h2>{result.name}</h2>
        </CardTitle>
        <CardDescription className="flex flex-wrap gap-2">
          <span className="bg-muted text-foreground inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" data-testid="skill-gap-status">
            {skillGapStatusLabel(result.status)}
          </span>
          {priority ? (
            <span className="inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium" data-testid="skill-gap-priority">
              {priority}
            </span>
          ) : null}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {measured ? (
          <div className="grid gap-3">
            <div className="grid gap-1">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Current</span>
                <span className="tabular-nums" data-testid="skill-gap-current">
                  {formatScore(result.current_score)}
                </span>
              </div>
              <ScoreBar value={result.current_score} label={`${result.name} current score`} />
            </div>
            <div className="grid gap-1">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Target</span>
                <span className="tabular-nums" data-testid="skill-gap-target-score">
                  {formatScore(result.target_score)}
                </span>
              </div>
              <ScoreBar value={result.target_score} label={`${result.name} target score`} />
            </div>
            <p className="text-sm" data-testid="skill-gap-gap">
              Gap: <span className="font-semibold tabular-nums">{formatScore(result.raw_gap)} points</span>
              {result.material_gap ? "" : " (below the material-gap threshold)"}
            </p>
            {result.priority_capped ? (
              <p className="text-muted-foreground text-xs">
                Capped at medium priority: the evidence quality is below the bound for high priority.
              </p>
            ) : null}
          </div>
        ) : (
          <div className="grid gap-1">
            <p className="text-sm font-medium" data-testid="skill-gap-unmeasured">
              {unmeasuredExplanation(result.status)}
            </p>
            {result.target_score !== null ? (
              <p className="text-muted-foreground text-sm">Target: {formatScore(result.target_score)} (no gap is computed without evidence)</p>
            ) : null}
            {result.current_score !== null ? <p className="text-muted-foreground text-sm">Current: {formatScore(result.current_score)}</p> : null}
          </div>
        )}

        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted-foreground">Evidence quality</dt>
          <dd className="tabular-nums">{formatPercent(result.evidence_quality) ?? "—"}</dd>
          {result.current_level ? (
            <>
              <dt className="text-muted-foreground">Competency level</dt>
              <dd>{levelLabel(result.current_level)}</dd>
            </>
          ) : null}
        </dl>

        {result.limitations.length > 0 ? (
          <div className="grid gap-1 rounded-lg border border-dashed p-3 text-xs">
            <p className="font-medium">Partially supported languages</p>
            {result.limitations.map((limitation) => (
              <p key={limitation.language} className="text-muted-foreground">
                {languageLabel(limitation.language)}: {limitation.note}
              </p>
            ))}
          </div>
        ) : null}

        <details className="text-sm">
          <summary className="cursor-pointer font-medium">Evidence ({result.evidence.length})</summary>
          <div className="mt-3 grid gap-2">
            {result.target_rationale ? <p className="text-muted-foreground text-xs">Target rationale: {result.target_rationale}</p> : null}
            <ul className="grid gap-2">
              {result.evidence.map((item) => (
                <li key={item.source} className="grid gap-0.5 rounded-lg border p-2 text-xs" data-testid={`skill-gap-evidence-${item.source}`}>
                  <span className="font-medium">{item.source ? componentLabel(item.source.split(".")[1] ?? item.source) : "—"}</span>
                  <span className="text-muted-foreground">
                    From CodeDNA <code className="font-mono">{item.source}</code> · {evidenceStatusLabel(item.status)}
                    {item.score !== null ? ` · evidence score ${formatScore(item.score)}` : ""}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        </details>
      </CardContent>
    </Card>
  );
}

function Provenance({ snapshot, projectId }: { snapshot: SkillGapSnapshot; projectId: string }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Source and calculation</h2>
        </CardTitle>
        <CardDescription>Which competency matrix was compared with which target.</CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-3 text-sm sm:grid-cols-[13rem_1fr]">
          <dt className="text-muted-foreground">Competency matrix</dt>
          <dd>
            {snapshot.competency_snapshot ? (
              <Link href={`/app/projects/${projectId}/competencies`} className="underline underline-offset-4">
                Competency version {snapshot.competency_version} · calculated {formatDateTime(snapshot.competency_snapshot.created_at)}
              </Link>
            ) : (
              "—"
            )}
          </dd>
          <dt className="text-muted-foreground">CodeDNA assessment</dt>
          <dd>
            <Link href={`/app/projects/${projectId}/dna/${snapshot.dna_snapshot_id}`} className="underline underline-offset-4">
              CodeDNA scoring {snapshot.dna_scoring_version}
            </Link>
          </dd>
          <dt className="text-muted-foreground">Source snapshot</dt>
          <dd>{snapshot.source_snapshot ? `v${snapshot.source_snapshot.version}, ${snapshot.source_snapshot.file_count} files` : "—"}</dd>
          <dt className="text-muted-foreground">Measured languages</dt>
          <dd>{snapshot.languages === null ? "Unknown" : snapshot.languages.map((language) => languageLabel(language)).join(", ") || "—"}</dd>
          <dt className="text-muted-foreground">Versions</dt>
          <dd>
            Skill gap {snapshot.skill_gap_version} · {targetProfileLabel(snapshot.target_profile.key)} {snapshot.target_profile.version}
          </dd>
          <dt className="text-muted-foreground">Specification fingerprint</dt>
          <dd>
            <code className="font-mono text-xs break-all">{snapshot.specification_fingerprint}</code>
          </dd>
        </dl>
      </CardContent>
    </Card>
  );
}
