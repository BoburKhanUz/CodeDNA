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
import type { AiAssessment, AssessmentClaim, AssessmentEvidence, Project } from "@/lib/api/types";
import { getAssessment, listAssessments, requestAssessment } from "@/lib/assessment/client";
import { factLabel, formatFact } from "@/lib/assessment/format";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";
import { listSkillGapSnapshots } from "@/lib/skill-gap/client";

/** How often a QUEUED or RUNNING assessment is re-read. */
export const POLL_INTERVAL_MS = 3000;

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "loaded"; project: Project; assessment: AiAssessment | null; latestSkillGapId: string | null };

type RequestState = { status: "idle" } | { status: "sending" } | { status: "unavailable" } | { status: "error"; error: unknown };

/**
 * AI assessment of a project (Phase 15): a non-authoritative, AI-generated
 * interpretation of the newest skill gap analysis. It shows what the API
 * returns, with the evidence every claim cites; it computes nothing, edits
 * nothing and has no free-text input.
 */
export function AssessmentView({ projectId }: { projectId: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const [request, setRequest] = useState<RequestState>({ status: "idle" });

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
    fetchAssessment(projectId).then(setState).catch(handleError);
  }, [projectId, valid, handleError]);

  useEffect(() => load(), [load]);

  // Poll while the assessment is being generated. No fake progress: only the server's status is shown.
  const pendingId =
    state.status === "loaded" && state.assessment && (state.assessment.status === "QUEUED" || state.assessment.status === "RUNNING")
      ? state.assessment.id
      : null;
  useEffect(() => {
    if (pendingId === null) return;
    const timer = setTimeout(() => {
      getAssessment(projectId, pendingId)
        // A late answer about an assessment that is no longer shown (a newer one was requested) is ignored.
        .then((assessment) =>
          setState((current) => (current.status === "loaded" && current.assessment?.id === assessment.id ? { ...current, assessment } : current)),
        )
        .catch(handleError);
    }, POLL_INTERVAL_MS);
    return () => clearTimeout(timer);
  }, [pendingId, state, projectId, handleError]);

  const submit = useCallback(() => {
    setRequest({ status: "sending" });
    requestAssessment(projectId)
      .then((assessment) => {
        setRequest({ status: "idle" });
        setState((current) => (current.status === "loaded" ? { ...current, assessment } : current));
      })
      .catch((error: unknown) => {
        if (isApiError(error) && error.status === 401) {
          handleError(error);
          return;
        }
        setRequest(isApiError(error) && error.code === "AI_ASSESSMENT_DISABLED" ? { status: "unavailable" } : { status: "error", error });
      });
  }, [projectId, handleError]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading AI assessment">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-24" />
        <Skeleton className="h-64" />
        <span className="sr-only">Loading AI assessment…</span>
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

  const { project, assessment, latestSkillGapId } = state;
  const archived = project.status === "ARCHIVED";
  const outdated = assessment !== null && latestSkillGapId !== null && assessment.lineage.skill_gap_snapshot_id !== latestSkillGapId;
  const canRequest = !archived && latestSkillGapId !== null && (assessment === null || assessment.status === "FAILED" || outdated);

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
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">AI Assessment</h1>
          <StatusBadge status={project.status} />
          <span className="inline-flex rounded-full border border-violet-300 bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-900" data-testid="assessment-ai-label">
            AI-generated interpretation
          </span>
        </div>
        <p className="text-muted-foreground max-w-3xl" data-testid="assessment-disclaimer">
          An AI model explains the deterministic results of the skill gap analysis in plain language. The AI does not determine
          or change any score, level, gap, priority or target: those come only from CodeDNA&apos;s versioned calculations, and
          they take precedence over anything written here.
        </p>
      </div>

      {request.status === "unavailable" ? (
        <Card data-testid="assessment-unavailable">
          <CardHeader>
            <CardTitle>
              <h2>AI interpretation is not available</h2>
            </CardTitle>
            <CardDescription>
              AI assessment is not enabled on this server. All deterministic results remain available on the Skill Gaps, Competency
              Matrix and CodeDNA pages.
            </CardDescription>
          </CardHeader>
        </Card>
      ) : null}
      {request.status === "error" ? <ApiErrorAlert error={request.error} /> : null}

      {assessment === null ? (
        <Card data-testid="assessment-none">
          <CardHeader>
            <CardTitle>
              <h2>No AI assessment yet</h2>
            </CardTitle>
            <CardDescription>
              {latestSkillGapId === null
                ? "An AI assessment interprets a skill gap analysis, which needs a completed static analysis of the project's source."
                : "Request an AI interpretation of the newest skill gap analysis. It is generated in the background and usually takes less than a minute."}
            </CardDescription>
          </CardHeader>
          {latestSkillGapId === null ? (
            <CardContent>
              <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-sm underline underline-offset-4">
                Open Skill Gaps
              </Link>
            </CardContent>
          ) : null}
        </Card>
      ) : null}

      {assessment !== null && (assessment.status === "QUEUED" || assessment.status === "RUNNING") ? (
        <Card data-testid={assessment.status === "QUEUED" ? "assessment-queued" : "assessment-processing"} role="status" aria-live="polite">
          <CardHeader>
            <CardTitle>
              <h2>{assessment.status === "QUEUED" ? "Waiting to start" : "Generating the interpretation"}</h2>
            </CardTitle>
            <CardDescription>
              {assessment.status === "QUEUED"
                ? "The assessment is queued. This page updates automatically."
                : "The AI model is writing the interpretation; it is checked against the evidence before it is shown. This page updates automatically."}
            </CardDescription>
          </CardHeader>
        </Card>
      ) : null}

      {assessment?.status === "FAILED" ? (
        <Card data-testid="assessment-failed">
          <CardHeader>
            <CardTitle>
              <h2>The interpretation could not be produced</h2>
            </CardTitle>
            <CardDescription>
              {assessment.failure?.message ?? "The assessment could not be completed."} No partial or substitute interpretation is
              shown. The deterministic results are not affected.
            </CardDescription>
          </CardHeader>
        </Card>
      ) : null}

      {outdated ? (
        <p className="text-muted-foreground text-sm" data-testid="assessment-outdated">
          This assessment interprets an earlier skill gap analysis. A newer analysis is available.
        </p>
      ) : null}

      {canRequest && request.status !== "unavailable" ? (
        <Button className="w-fit" onClick={submit} disabled={request.status === "sending"} data-testid="assessment-request">
          {request.status === "sending"
            ? "Requesting…"
            : assessment === null
              ? "Generate AI assessment"
              : assessment.status === "FAILED"
                ? "Try again"
                : "Interpret the newest analysis"}
        </Button>
      ) : null}
      {archived && assessment === null ? (
        <p className="text-muted-foreground text-sm">This project is archived: it keeps its history but no new assessment can be requested.</p>
      ) : null}

      {assessment?.status === "SUCCEEDED" && assessment.output ? <Interpretation assessment={assessment} /> : null}
      {assessment !== null ? <Provenance assessment={assessment} projectId={project.id} /> : null}

      <p className="text-muted-foreground max-w-3xl text-xs">
        AI-generated text can be incomplete or wrong. It describes measured characteristics of the analyzed source code only and
        does not establish developer seniority, intelligence, personality, professional worth, or future potential.
      </p>
    </div>
  );
}

async function fetchAssessment(projectId: string): Promise<State> {
  const [project, assessments, gaps] = await Promise.all([
    getProject(projectId),
    listAssessments(projectId, 1, 1),
    listSkillGapSnapshots(projectId, 1, 1),
  ]);
  const latest = assessments.data[0];
  return {
    status: "loaded",
    project,
    assessment: latest === undefined ? null : await getAssessment(projectId, latest.id),
    latestSkillGapId: gaps.data[0]?.id ?? null,
  };
}

function Interpretation({ assessment }: { assessment: AiAssessment }) {
  const output = assessment.output;
  const catalog = new Map(assessment.evidence.map((item) => [item.id, item]));
  if (output === null) return null;

  const sections: { key: string; title: string; claims: AssessmentClaim[]; empty: string }[] = [
    { key: "strengths", title: "Strengths", claims: output.strengths, empty: "No strengths are stated for this analysis." },
    { key: "areas", title: "Areas to improve", claims: output.areas_to_improve, empty: "No areas to improve are stated for this analysis." },
    { key: "insights", title: "Development insights", claims: output.development_insights, empty: "No development insights are stated." },
  ];

  return (
    <div className="grid gap-6" data-testid="assessment-ready">
      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Summary</h2>
          </CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3">
          <p data-testid="assessment-summary">{output.summary.text}</p>
          <EvidenceRefs refs={output.summary.evidence_refs} catalog={catalog} />
        </CardContent>
      </Card>

      {sections.map((section) => (
        <section key={section.key} className="grid gap-3" aria-labelledby={`assessment-${section.key}`} data-testid={`assessment-${section.key}`}>
          <h2 id={`assessment-${section.key}`} className="text-lg font-semibold">
            {section.title}
          </h2>
          {section.claims.length === 0 ? (
            <p className="text-muted-foreground text-sm">{section.empty}</p>
          ) : (
            <ul className="grid gap-3 md:grid-cols-2">
              {section.claims.map((claim, index) => (
                <li key={index} className="grid gap-2 rounded-xl border p-4">
                  <h3 className="font-medium">{claim.title}</h3>
                  <p className="text-sm">{claim.description}</p>
                  <EvidenceRefs refs={claim.evidence_refs} catalog={catalog} />
                </li>
              ))}
            </ul>
          )}
        </section>
      ))}

      <section className="grid gap-3" aria-labelledby="assessment-limitations" data-testid="assessment-limitations">
        <h2 id="assessment-limitations" className="text-lg font-semibold">
          Limitations
        </h2>
        <ul className="grid gap-3">
          {output.limitations.map((limitation, index) => (
            <li key={index} className="grid gap-2 rounded-xl border border-dashed p-4">
              <p className="text-sm">{limitation.description}</p>
              <EvidenceRefs refs={limitation.evidence_refs} catalog={catalog} />
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

/** The evidence a claim cites, resolved from the catalog the interpretation was built from. */
function EvidenceRefs({ refs, catalog }: { refs: string[]; catalog: Map<string, AssessmentEvidence> }) {
  return (
    <details className="text-xs">
      <summary className="text-muted-foreground cursor-pointer">
        Evidence: {refs.map((ref) => catalog.get(ref)?.label ?? ref).join(", ")}
      </summary>
      <ul className="mt-2 grid gap-2">
        {refs.map((ref) => {
          const item = catalog.get(ref);
          return (
            <li key={ref} className="grid gap-1 rounded-lg border p-2" data-testid={`assessment-evidence-${ref}`}>
              <span className="font-medium">
                {item?.label ?? ref} <code className="text-muted-foreground font-mono">{ref}</code>
              </span>
              {item ? (
                <>
                  <span className="text-muted-foreground">{item.description}</span>
                  <dl className="grid grid-cols-[auto_1fr] gap-x-3">
                    {Object.entries(item.facts).map(([key, value]) => (
                      <div key={key} className="contents">
                        <dt className="text-muted-foreground">{factLabel(key)}</dt>
                        <dd className="tabular-nums">{formatFact(value)}</dd>
                      </div>
                    ))}
                  </dl>
                </>
              ) : null}
            </li>
          );
        })}
      </ul>
    </details>
  );
}

function Provenance({ assessment, projectId }: { assessment: AiAssessment; projectId: string }) {
  const { versions, provider, fingerprints, lineage } = assessment;

  return (
    <Card data-testid="assessment-provenance">
      <CardHeader>
        <CardTitle>
          <h2>Model, versions and lineage</h2>
        </CardTitle>
        <CardDescription>What produced this interpretation and which deterministic results it is based on.</CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-3 text-sm sm:grid-cols-[13rem_1fr]">
          <dt className="text-muted-foreground">Model</dt>
          <dd data-testid="assessment-model">
            {provider.model} ({provider.name})
            {provider.served_model && provider.served_model !== provider.model ? ` · served as ${provider.served_model}` : ""}
          </dd>
          <dt className="text-muted-foreground">Requested</dt>
          <dd>{formatDateTime(assessment.created_at)}</dd>
          <dt className="text-muted-foreground">Completed</dt>
          <dd>{assessment.completed_at ? formatDateTime(assessment.completed_at) : "—"}</dd>
          <dt className="text-muted-foreground">Skill gap analysis</dt>
          <dd>
            <Link href={`/app/projects/${projectId}/skill-gaps`} className="underline underline-offset-4">
              Skill gap {versions.skill_gap}
              {versions.target_profile ? ` · ${versions.target_profile} ${versions.target_profile_version ?? ""}` : ""}
            </Link>
          </dd>
          <dt className="text-muted-foreground">Competency matrix</dt>
          <dd>
            <Link href={`/app/projects/${projectId}/competencies`} className="underline underline-offset-4">
              Competency {versions.competency}
            </Link>
          </dd>
          <dt className="text-muted-foreground">CodeDNA assessment</dt>
          <dd>
            <Link href={`/app/projects/${projectId}/dna/${lineage.dna_snapshot_id}`} className="underline underline-offset-4">
              CodeDNA scoring {versions.dna_scoring}
            </Link>
          </dd>
          <dt className="text-muted-foreground">Versions</dt>
          <dd data-testid="assessment-versions">
            Assessment {versions.assessment} · prompt {versions.prompt} · output {versions.output_schema} · input {versions.input_schema}
          </dd>
          <dt className="text-muted-foreground">Input fingerprint</dt>
          <dd>
            <code className="font-mono text-xs break-all">{fingerprints.input}</code>
          </dd>
          <dt className="text-muted-foreground">Prompt fingerprint</dt>
          <dd>
            <code className="font-mono text-xs break-all">{fingerprints.prompt}</code>
          </dd>
          {fingerprints.output ? (
            <>
              <dt className="text-muted-foreground">Output fingerprint</dt>
              <dd>
                <code className="font-mono text-xs break-all">{fingerprints.output}</code>
              </dd>
            </>
          ) : null}
        </dl>
      </CardContent>
    </Card>
  );
}
