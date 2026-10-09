"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { InsightPanel } from "@/components/insights/insight-panel";
import { CodeEditor } from "@/components/challenge/code-editor";
import { EvaluationFeedback } from "@/components/challenge/evaluation-feedback";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { Challenge, ChallengeSubmission, Project } from "@/lib/api/types";
import { getChallenge, getSubmission, submitSolution } from "@/lib/challenge/client";
import { challengeStatusLabel, competencyName, difficultyLabel, formatValue, ruleLabel, ruleLimit, selectionRuleLabel, submissionStatusLabel } from "@/lib/challenge/format";
import { formatScore } from "@/lib/dna/format";
import { usePageVisible } from "@/lib/polling/use-page-visible";
import { getProject, isProjectId } from "@/lib/projects/client";
import { formatDateTime } from "@/lib/projects/format";

/** How often a QUEUED or RUNNING attempt is re-read. */
export const POLL_INTERVAL_MS = 2000;

type State =
  | { status: "loading" }
  | { status: "not-found" }
  | { status: "error"; error: unknown }
  | { status: "loaded"; project: Project; challenge: Challenge; selected: ChallengeSubmission | null };

type SubmitState = { status: "idle" } | { status: "sending" } | { status: "error"; error: unknown };

/**
 * One coding challenge (Phase 16): the exercise, why it was selected, a
 * single-file editor, and deterministic feedback for each attempt. A
 * practice layer: no AI, no hints, no chat, and no effect on CodeDNA.
 */
export function ChallengeDetail({ projectId, challengeId }: { projectId: string; challengeId: string }) {
  const router = useRouter();
  const valid = isProjectId(projectId) && isProjectId(challengeId);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const [source, setSource] = useState<string | null>(null);
  const [submit, setSubmit] = useState<SubmitState>({ status: "idle" });

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
    fetchChallenge(projectId, challengeId).then(setState).catch(handleError);
  }, [projectId, challengeId, valid, handleError]);

  useEffect(() => load(), [load]);

  // Poll a pending attempt, whichever attempt is being viewed; when it finishes, reload the challenge
  // (status and attempt counts). Late answers never replace the attempt the user chose to view.
  const pending =
    state.status === "loaded"
      ? state.selected && (state.selected.status === "QUEUED" || state.selected.status === "RUNNING")
        ? state.selected.id
        : (state.challenge.recent_attempts.find((attempt) => attempt.status === "QUEUED" || attempt.status === "RUNNING")?.id ?? null)
      : null;
  // Paused while the tab is hidden (Phase 26); resumes when it is shown again.
  const visible = usePageVisible();
  useEffect(() => {
    if (pending === null || !visible) return;
    const timer = setTimeout(() => {
      getSubmission(projectId, challengeId, pending)
        .then((submission) => {
          if (submission.status === "QUEUED" || submission.status === "RUNNING") {
            setState((current) =>
              current.status === "loaded"
                ? {
                    ...current,
                    selected: current.selected?.id === submission.id ? submission : current.selected,
                    challenge: {
                      ...current.challenge,
                      recent_attempts: current.challenge.recent_attempts.map((attempt) => (attempt.id === submission.id ? { ...attempt, status: submission.status } : attempt)),
                    },
                  }
                : current,
            );
            return;
          }
          return fetchChallenge(projectId, challengeId, submission.id).then((next) =>
            setState((current) =>
              current.status === "loaded" && next.status === "loaded" && current.selected !== null && current.selected.id !== submission.id
                ? { ...next, selected: current.selected }
                : next,
            ),
          );
        })
        .catch(handleError);
    }, POLL_INTERVAL_MS);
    return () => clearTimeout(timer);
  }, [pending, visible, state, projectId, challengeId, handleError]);

  const send = useCallback(
    (language: string, code: string) => {
      setSubmit({ status: "sending" });
      submitSolution(projectId, challengeId, language, code)
        .then((submission) => {
          setSubmit({ status: "idle" });
          setState((current) =>
            current.status === "loaded"
              ? { ...current, selected: submission, challenge: { ...current.challenge, status: "EVALUATING", recent_attempts: [submission, ...current.challenge.recent_attempts] } }
              : current,
          );
        })
        .catch((error: unknown) => (isApiError(error) && error.status === 401 ? handleError(error) : setSubmit({ status: "error", error })));
    },
    [projectId, challengeId, handleError],
  );

  const select = useCallback(
    (submissionId: string) => {
      getSubmission(projectId, challengeId, submissionId)
        .then((selected) => setState((current) => (current.status === "loaded" ? { ...current, selected } : current)))
        .catch(handleError);
    },
    [projectId, challengeId, handleError],
  );

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading challenge">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-40" />
        <Skeleton className="h-64" />
        <span className="sr-only">Loading challenge…</span>
      </div>
    );
  }

  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">Challenge not found</h1>
        <p className="text-muted-foreground">It does not exist, or it belongs to another account.</p>
        <Link href={isProjectId(projectId) ? `/app/projects/${projectId}/challenges` : "/app/projects"} className="underline underline-offset-4">
          Back to challenges
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

  const { project, challenge, selected } = state;
  const exercise = challenge.challenge;
  const archived = project.status === "ARCHIVED";
  const closed = challenge.status === "PASSED" || challenge.status === "FAILED";
  const code = source ?? exercise?.starter_code ?? "";
  const canSubmit = !archived && !closed && challenge.status === "ASSIGNED" && challenge.evaluation_available && submit.status !== "sending";
  const why = challenge.selection;

  return (
    <div className="grid max-w-5xl gap-6">
      <div className="grid gap-2">
        <nav aria-label="Related pages" className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <Link href={`/app/projects/${project.id}/challenges`} className="text-muted-foreground underline-offset-4 hover:underline">
            ← Challenges
          </Link>
          <Link href={`/app/projects/${project.id}/skill-gaps`} className="text-muted-foreground underline-offset-4 hover:underline">
            Skill Gaps
          </Link>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{exercise?.title ?? challenge.definition.key}</h1>
          <span className="bg-muted inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" data-testid="challenge-status">
            {challengeStatusLabel(challenge.status)}
          </span>
        </div>
        <p className="text-muted-foreground text-sm" data-testid="challenge-meta">
          {competencyName(challenge.competency_key)} · {difficultyLabel(challenge.difficulty)} · {exercise?.runtime ?? challenge.language}
          {exercise ? ` · about ${exercise.estimated_minutes} minutes` : ""} · attempts {challenge.attempts_used} of {challenge.max_attempts}
        </p>
        <p className="max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950" data-testid="challenge-notice">
          {challenge.notice}
        </p>
      </div>

      <Card data-testid="challenge-why">
        <CardHeader>
          <CardTitle>
            <h2>Why this challenge</h2>
          </CardTitle>
          <CardDescription>{selectionRuleLabel(why.rule)}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-1 text-sm">
          {challenge.gap ? (
            <p data-testid="challenge-gap">
              {competencyName(challenge.gap.competency_key)}: {challenge.gap.priority ? `${challenge.gap.priority.toLowerCase()} priority, ` : ""}current{" "}
              {formatScore(challenge.gap.current_score)}, target {formatScore(challenge.gap.target_score)}, gap {formatScore(challenge.gap.raw_gap)} points (out of
              100), from the skill gap analysis of {formatDateTime(challenge.created_at)}.
            </p>
          ) : null}
          <p className="text-muted-foreground">
            Preferred difficulty for this priority: {difficultyLabel(why.preferred_difficulty).toLowerCase()}; selected:{" "}
            {difficultyLabel(why.selected_difficulty).toLowerCase()}. Selection {why.selection_version}, catalog {why.catalog_version}.
          </p>
        </CardContent>
      </Card>

      {exercise ? (
        <Card>
          <CardHeader>
            <CardTitle>
              <h2>The exercise</h2>
            </CardTitle>
            <CardDescription>{exercise.summary}</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 text-sm">
            <ul className="grid list-disc gap-1 pl-5" data-testid="challenge-instructions">
              {exercise.instructions.map((line, index) => (
                <li key={`${index}:${line}`}>{line}</li>
              ))}
            </ul>
            <div className="grid gap-1">
              <h3 className="font-medium">Constraints</h3>
              <ul className="grid list-disc gap-1 pl-5">
                {exercise.constraints.map((line, index) => (
                  <li key={`${index}:${line}`}>{line}</li>
                ))}
              </ul>
            </div>
            <div className="grid gap-1">
              <h3 className="font-medium">Acceptance criteria</h3>
              <ul className="grid gap-1" data-testid="challenge-criteria">
                {exercise.acceptance_criteria.map((criterion) => (
                  <li key={criterion.id}>
                    {criterion.id}: {criterion.description}
                  </li>
                ))}
              </ul>
              <p className="text-muted-foreground">
                Rules: {Object.entries(exercise.rules).map(([rule, limit]) => `${ruleLabel(rule).toLowerCase()} ${ruleLimit(rule, limit)}`).join("; ")}.
              </p>
            </div>
            <div className="grid gap-1">
              <h3 className="font-medium">Examples</h3>
              <ul className="grid gap-1 font-mono text-xs" data-testid="challenge-examples">
                {exercise.examples.map((example) => (
                  <li key={example.id}>
                    {exercise.entrypoint}({example.args.map(formatValue).join(", ")}) → {formatValue(example.expected)}
                    {example.description ? <span className="text-muted-foreground font-sans"> — {example.description}</span> : null}
                  </li>
                ))}
              </ul>
              <p className="text-muted-foreground">Plus {exercise.hidden_case_count} hidden tests, reported by status only.</p>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <section aria-labelledby="solution-heading" className="grid gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="solution-heading" className="text-lg font-semibold">
            Your solution ({exercise?.runtime ?? challenge.language}, one file)
          </h2>
          {!closed && exercise ? (
            <Button variant="outline" size="sm" onClick={() => setSource(null)} disabled={source === null}>
              Reset to starter code
            </Button>
          ) : null}
        </div>
        <CodeEditor value={code} onChange={closed || archived ? undefined : setSource} readOnly={closed || archived} label="Solution source" />
        {submit.status === "error" ? <ApiErrorAlert error={submit.error} /> : null}
        {!challenge.evaluation_available && !closed ? (
          <p className="text-sm" data-testid="evaluation-unavailable">
            Evaluation is not available right now, so attempts cannot be submitted. Your code is not lost while this page stays open.
          </p>
        ) : null}
        {archived ? <p className="text-muted-foreground text-sm">This project is archived: the challenge and its attempts stay readable.</p> : null}
        {closed ? (
          <p className="text-sm" data-testid="challenge-closed">
            {challenge.status === "PASSED" ? "This challenge is passed." : "All attempts are used."} It accepts no further attempts.
          </p>
        ) : (
          <Button className="w-fit" onClick={() => send(challenge.language, code)} disabled={!canSubmit} data-testid="challenge-submit">
            {submit.status === "sending" ? "Submitting…" : challenge.status === "EVALUATING" ? "Evaluating…" : "Submit attempt"}
          </Button>
        )}
      </section>

      {selected ? (
        <section aria-labelledby="feedback-heading" className="grid gap-3">
          <h2 id="feedback-heading" className="text-lg font-semibold">
            Feedback
          </h2>
          <EvaluationFeedback submission={selected} />
          {selected.status === "PASSED" || selected.status === "FAILED" ? (
            <InsightPanel projectId={project.id} kind="CHALLENGE_FEEDBACK" subjectId={selected.id} canRequest={!archived} />
          ) : null}
        </section>
      ) : null}

      {challenge.recent_attempts.length > 0 ? (
        <section aria-labelledby="attempts-heading" className="grid gap-2">
          <h2 id="attempts-heading" className="text-lg font-semibold">
            Attempts
          </h2>
          <ul className="grid gap-1 text-sm" data-testid="attempts">
            {challenge.recent_attempts.map((attempt) => (
              <li key={attempt.id}>
                <button
                  type="button"
                  className="underline-offset-4 hover:underline"
                  onClick={() => select(attempt.id)}
                  aria-current={selected?.id === attempt.id ? "true" : undefined}
                >
                  Attempt {attempt.attempt_number}: {submissionStatusLabel(attempt.status)}
                  {attempt.tests ? ` — ${attempt.tests.passed} of ${attempt.tests.total} tests` : ""} · {formatDateTime(attempt.created_at)}
                </button>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <Card data-testid="challenge-provenance">
        <CardHeader>
          <CardTitle>
            <h2>Versions and lineage</h2>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-2 text-sm sm:grid-cols-[13rem_1fr]">
            <dt className="text-muted-foreground">Challenge</dt>
            <dd>
              {challenge.definition.key} {challenge.versions.definition}
            </dd>
            <dt className="text-muted-foreground">Versions</dt>
            <dd>
              Catalog {challenge.versions.catalog} · {challenge.versions.selection} · {challenge.versions.evaluation}
            </dd>
            <dt className="text-muted-foreground">Test suite fingerprint</dt>
            <dd>
              <code className="font-mono text-xs break-all">{challenge.fingerprints.test_suite}</code>
            </dd>
            <dt className="text-muted-foreground">Skill gap analysis</dt>
            <dd>
              <Link href={`/app/projects/${project.id}/skill-gaps`} className="underline underline-offset-4">
                {challenge.lineage.skill_gap_snapshot_id}
              </Link>
            </dd>
            <dt className="text-muted-foreground">Assigned</dt>
            <dd>{formatDateTime(challenge.created_at)}</dd>
          </dl>
        </CardContent>
      </Card>
    </div>
  );
}

async function fetchChallenge(projectId: string, challengeId: string, selectedId?: string): Promise<State> {
  const [project, challenge] = await Promise.all([getProject(projectId), getChallenge(projectId, challengeId)]);
  const latest = selectedId ?? challenge.recent_attempts[0]?.id;
  return {
    status: "loaded",
    project,
    challenge,
    selected: latest ? await getSubmission(projectId, challengeId, latest) : null,
  };
}
