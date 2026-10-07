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
import { COMPETENCY_STATUSES, type Competency, type CompetencyEvidence, type CompetencyLevelBound, type CompetencySnapshot, type Project } from "@/lib/api/types";
import { getCompetencySnapshot, listCompetencySnapshots } from "@/lib/competency/client";
import {
  competencyStatusExplanation,
  competencyStatusLabel,
  levelBadge,
  levelLabel,
  levelMeaning,
} from "@/lib/competency/format";
import { listDnaSnapshots } from "@/lib/dna/client";
import {
  componentLabel,
  evidenceStatusLabel,
  formatCount,
  formatDecimal,
  formatPercent,
  formatScore,
  formatWeight,
  metricLabel,
} from "@/lib/dna/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime, languageLabel } from "@/lib/projects/format";

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "empty"; project: Project; hasDna: boolean }
  | { status: "ready"; project: Project; snapshot: CompetencySnapshot };

/**
 * The Competency Matrix of a project (Phase 13): the newest competency
 * snapshot, derived by the backend from a CodeDNA assessment. Read-only; it
 * renders the API's values and computes none.
 */
export function CompetencyMatrix({ projectId }: { projectId: string }) {
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
    fetchMatrix(projectId).then(setState).catch(handleError);
  }, [projectId, valid, handleError]);

  useEffect(() => load(), [load]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-6xl gap-6" role="status" aria-label="Loading Competency Matrix">
        <Skeleton className="h-8 w-64" />
        <div className="grid gap-4 md:grid-cols-2">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-56" />
          ))}
        </div>
        <span className="sr-only">Loading Competency Matrix…</span>
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
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <Link href={`/app/projects/${project.id}`} className="text-muted-foreground underline-offset-4 hover:underline">
            ← {project.name}
          </Link>
          <Link href={`/app/projects/${project.id}/dna`} className="text-muted-foreground underline-offset-4 hover:underline">
            CodeDNA
          </Link>
          <Link href={`/app/projects/${project.id}/roadmap`} className="text-muted-foreground underline-offset-4 hover:underline">
            Learning Roadmap
          </Link>
          <Link href={`/app/projects/${project.id}/growth`} className="text-muted-foreground underline-offset-4 hover:underline">
            Growth
          </Link>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">Competency Matrix</h1>
          <StatusBadge status={project.status} />
        </div>
        <p className="text-muted-foreground max-w-3xl">
          Engineering competencies supported by evidence in the analyzed source code of {project.name}, derived
          deterministically from its CodeDNA assessment.
        </p>
        {state.status === "ready" ? (
          <p className="text-muted-foreground text-sm">
            Competency version {state.snapshot.competency_version} · calculated {formatDateTime(state.snapshot.created_at)}
            {state.snapshot.source_snapshot ? ` · source snapshot v${state.snapshot.source_snapshot.version}` : ""}
          </p>
        ) : null}
      </div>

      {state.status === "empty" ? (
        <Card data-testid="competency-empty">
          <CardHeader>
            <CardTitle>
              <h2>No competency matrix is available yet.</h2>
            </CardTitle>
            <CardDescription>
              {state.hasDna
                ? "A CodeDNA assessment exists, but no competency matrix has been derived from it yet."
                : "A competency matrix is derived from a CodeDNA assessment, which needs a completed static analysis of the project's source."}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Link href={`/app/projects/${project.id}/dna`} className="text-sm underline underline-offset-4">
              Open CodeDNA
            </Link>
          </CardContent>
        </Card>
      ) : (
        <Matrix snapshot={state.snapshot} projectId={project.id} />
      )}

      <p className="text-muted-foreground max-w-3xl text-xs">
        CodeDNA competency results describe deterministic evidence observed in analyzed source code. They do not establish
        developer seniority, intelligence, personality, professional level, or future potential.
      </p>
    </div>
  );
}

async function fetchMatrix(projectId: string): Promise<State> {
  const [project, list] = await Promise.all([getProject(projectId), listCompetencySnapshots(projectId, 1, 1)]);
  const latest = list.data[0];
  if (latest === undefined) {
    const dna = await listDnaSnapshots(projectId, 1, 1);
    return { status: "empty", project, hasDna: dna.meta.total > 0 };
  }
  return { status: "ready", project, snapshot: await getCompetencySnapshot(projectId, latest.id) };
}

function Matrix({ snapshot, projectId }: { snapshot: CompetencySnapshot; projectId: string }) {
  const { summary } = snapshot;

  return (
    <div className="grid gap-6">
      {snapshot.status === "INSUFFICIENT_DATA" ? (
        <Card data-testid="competency-insufficient">
          <CardHeader>
            <CardTitle>
              <h2>Insufficient evidence</h2>
            </CardTitle>
            <CardDescription>
              None of the competencies could be assessed: the analyzed source does not contain enough supported evidence.
              Each competency below says why. This is not a low result.
            </CardDescription>
          </CardHeader>
        </Card>
      ) : null}

      <p className="text-sm" data-testid="competency-summary">
        {summary.statuses.ASSESSED} of {summary.competencies} competencies assessed
        {COMPETENCY_STATUSES.filter((status) => status !== "ASSESSED" && summary.statuses[status] > 0)
          .map((status) => ` · ${summary.statuses[status]} ${competencyStatusLabel(status).toLowerCase()}`)
          .join("")}
      </p>

      <div className="grid items-start gap-4 md:grid-cols-2">
        {snapshot.competencies.map((competency) => (
          <CompetencyCard key={competency.key} competency={competency} levels={snapshot.levels} />
        ))}
      </div>

      <Card data-testid="skill-gap-link">
        <CardHeader>
          <CardTitle>
            <h2>Skill Gaps</h2>
          </CardTitle>
          <CardDescription>
            Compares these competency scores with a versioned engineering target and lists the measurable differences.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Link href={`/app/projects/${projectId}/skill-gaps`} className="text-sm font-medium underline underline-offset-4">
            View Skill Gaps →
          </Link>
        </CardContent>
      </Card>

      <Provenance snapshot={snapshot} projectId={projectId} />
    </div>
  );
}

function CompetencyCard({ competency, levels }: { competency: Competency; levels: CompetencyLevelBound[] | null }) {
  const assessed = competency.status === "ASSESSED" && competency.score !== null;
  const badge = assessed ? levelBadge(competency.level) : null;

  return (
    <Card className="gap-4" data-testid={`competency-${competency.key}`}>
      <CardHeader>
        <CardTitle>
          <h2>{competency.name}</h2>
        </CardTitle>
        {competency.description ? <CardDescription>{competency.description}</CardDescription> : null}
      </CardHeader>
      <CardContent className="grid gap-4">
        {assessed ? (
          <div className="grid gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <p className="text-3xl font-semibold tracking-tight tabular-nums">
                {formatScore(competency.score)}
                <span className="text-muted-foreground ml-1 text-base font-normal">/ 100</span>
              </p>
              {badge ? (
                <span className="bg-muted inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" data-testid="competency-level">
                  {badge}
                </span>
              ) : null}
            </div>
            <ScoreBar value={competency.score} label={`${competency.name} score`} />
            {levelMeaning(competency.level) ? <p className="text-muted-foreground text-sm">{levelMeaning(competency.level)}</p> : null}
          </div>
        ) : (
          <div className="grid gap-1">
            <p className="text-xl font-semibold" data-testid="competency-status">
              {competencyStatusLabel(competency.status)}
            </p>
            <p className="text-muted-foreground text-sm">{competencyStatusExplanation(competency.status)}</p>
          </div>
        )}

        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted-foreground">Evidence quality</dt>
          <dd className="tabular-nums">{formatPercent(competency.evidence_quality) ?? "—"}</dd>
          <dt className="text-muted-foreground">Evidence available</dt>
          <dd className="tabular-nums">
            {competency.evidence.filter((item) => item.status === "AVAILABLE").length} of {competency.evidence.length}
          </dd>
        </dl>

        {competency.limitations.length > 0 ? (
          <div className="grid gap-1 rounded-lg border border-dashed p-3 text-xs" data-testid="competency-limitations">
            <p className="font-medium">Partially supported languages</p>
            {competency.limitations.map((limitation) => (
              <p key={limitation.language} className="text-muted-foreground">
                {languageLabel(limitation.language)}: {limitation.note}
              </p>
            ))}
          </div>
        ) : null}

        <details className="text-sm">
          <summary className="cursor-pointer font-medium">Why this result ({competency.evidence.length} evidence)</summary>
          <div className="mt-3 grid gap-3">
            {competency.evidence_quality_terms ? (
              <p className="text-muted-foreground text-xs">
                Evidence quality = 50% parse coverage ({formatPercent(competency.evidence_quality_terms.parse_coverage)}) + 25% evidence
                volume ({formatPercent(competency.evidence_quality_terms.evidence_volume)}) + 25% evidence availability (
                {formatPercent(competency.evidence_quality_terms.evidence_availability)}). It describes the input, not certainty.
              </p>
            ) : null}
            <ul className="grid gap-3">
              {competency.evidence.map((item) => (
                <EvidenceItem key={item.source} evidence={item} />
              ))}
            </ul>
            {levels ? (
              <div className="grid gap-1 text-xs">
                <p className="font-medium">Level boundaries (score out of 100)</p>
                <ul className="text-muted-foreground grid gap-0.5">
                  {levels.map((bound) => (
                    <li key={bound.level}>
                      Level {bound.ordinal} · {levelLabel(bound.level) ?? bound.name}: from {formatScore(bound.minimum_score)}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </div>
        </details>
      </CardContent>
    </Card>
  );
}

function EvidenceItem({ evidence }: { evidence: CompetencyEvidence }) {
  const available = evidence.status === "AVAILABLE";
  const share = evidence.share === true;
  const seen = new Set<string>();
  const metrics = [...evidence.numerator, ...evidence.denominator].filter((m) => !seen.has(m.metric) && seen.add(m.metric));

  return (
    <li className="grid gap-1 rounded-lg border p-3" data-testid={`competency-evidence-${evidence.source}`}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="font-medium">{evidence.component ? componentLabel(evidence.component) : evidence.source}</span>
        <span className={available ? "text-muted-foreground text-xs" : "text-xs font-medium"}>{evidenceStatusLabel(evidence.status)}</span>
      </div>
      <p className="text-muted-foreground text-xs">
        From CodeDNA <code className="font-mono">{evidence.source}</code>
        {evidence.rationale ? ` — ${evidence.rationale}` : ""}
      </p>
      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-0.5">
        {metrics.map((metric) => (
          <MetricRow key={metric.metric} metric={metric.metric} value={metric.value} />
        ))}
        {available ? (
          <>
            <dt className="text-muted-foreground">Measured value</dt>
            <dd className="tabular-nums">{share ? formatPercent(evidence.value) : formatDecimal(evidence.value)}</dd>
            <dt className="text-muted-foreground">Evidence score</dt>
            <dd className="tabular-nums">{formatScore(evidence.score)}</dd>
          </>
        ) : null}
        <dt className="text-muted-foreground">Weight in competency</dt>
        <dd className="tabular-nums">{formatWeight(evidence.weight) ?? "—"}</dd>
      </dl>
      {evidence.status === "INSUFFICIENT_EVIDENCE" && evidence.minimum_denominator !== null ? (
        <p className="text-muted-foreground text-xs">
          Needs at least {evidence.minimum_denominator} {evidence.denominator.map((m) => metricLabel(m.metric).toLowerCase()).join(" + ")} to be measured.
        </p>
      ) : null}
      {available && evidence.best !== null && evidence.worst !== null ? (
        <p className="text-muted-foreground text-xs">
          Scores 100 at {share ? formatPercent(evidence.best) : formatDecimal(evidence.best)} or less and 0 at{" "}
          {share ? formatPercent(evidence.worst) : formatDecimal(evidence.worst)} or more.
        </p>
      ) : null}
    </li>
  );
}

function MetricRow({ metric, value }: { metric: string; value: number | null }) {
  return (
    <>
      <dt className="text-muted-foreground">{metricLabel(metric)}</dt>
      <dd className="tabular-nums">{value === null ? "Not available" : formatCount(value)}</dd>
    </>
  );
}

function Provenance({ snapshot, projectId }: { snapshot: CompetencySnapshot; projectId: string }) {
  const dna = snapshot.dna_snapshot;

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Source and calculation</h2>
        </CardTitle>
        <CardDescription>Where the evidence comes from and how this matrix was produced.</CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-3 text-sm sm:grid-cols-[13rem_1fr]">
          <dt className="text-muted-foreground">CodeDNA assessment</dt>
          <dd>
            {dna ? (
              <Link href={`/app/projects/${projectId}/dna/${dna.id}`} className="underline underline-offset-4">
                {dna.status === "READY" ? `Score ${formatScore(dna.overall_score)}` : "Insufficient data"} · calculated{" "}
                {formatDateTime(dna.created_at)}
              </Link>
            ) : (
              "—"
            )}
          </dd>
          <dt className="text-muted-foreground">Source snapshot</dt>
          <dd>{snapshot.source_snapshot ? `v${snapshot.source_snapshot.version}, ${formatCount(snapshot.source_snapshot.file_count)} files` : "—"}</dd>
          <dt className="text-muted-foreground">Measured languages</dt>
          <dd>{snapshot.languages === null ? "Unknown" : snapshot.languages.map((language) => languageLabel(language)).join(", ") || "—"}</dd>
          <dt className="text-muted-foreground">Analysis run</dt>
          <dd>
            {snapshot.analysis_run ? (
              <>
                <code className="font-mono text-xs">{snapshot.analysis_run.id}</code> · {snapshot.analysis_run.status}
              </>
            ) : (
              "—"
            )}
          </dd>
          <dt className="text-muted-foreground">Versions</dt>
          <dd>
            Competency {snapshot.competency_version} · CodeDNA scoring {snapshot.dna_scoring_version}
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
