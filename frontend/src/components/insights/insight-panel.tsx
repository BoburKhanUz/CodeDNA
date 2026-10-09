"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { AiInsight, AiStatus, InsightClaim, InsightEvidence, InsightKind } from "@/lib/api/types";
import { factLabel, formatFact } from "@/lib/assessment/format";
import { getAiStatus, getInsight, listInsights, requestInsight } from "@/lib/insights/client";
import { usePageVisible } from "@/lib/polling/use-page-visible";

/** How often a QUEUED or RUNNING insight is re-read (paused while the tab is hidden). */
export const INSIGHT_POLL_MS = 4000;

const TITLES: Record<InsightKind, string> = {
  GROWTH_INTERPRETATION: "AI explanation of this growth",
  ROADMAP_GUIDANCE: "AI guidance for this roadmap",
  CHALLENGE_FEEDBACK: "AI explanation of this result",
};

const PLAN_CODES: readonly string[] = ["FEATURE_NOT_INCLUDED", "SUBSCRIPTION_INACTIVE", "QUOTA_EXCEEDED"];

type State =
  | { status: "loading" }
  | { status: "error"; error: unknown }
  | { status: "loaded"; ai: AiStatus; insight: AiInsight | null };

type RequestState = { status: "idle" } | { status: "sending" } | { status: "plan" } | { status: "error"; error: unknown };

/**
 * An evidence-grounded AI explanation of one deterministic result (Phase 29):
 * a growth snapshot, a learning roadmap or an evaluated challenge attempt.
 *
 * - It never replaces the deterministic data shown around it: it is labeled
 *   AI-generated and non-authoritative, and every statement lists the
 *   evidence it cites, resolved to the stored facts.
 * - Everything is rendered as plain text (no HTML or Markdown).
 * - Only the server's status is shown: no placeholder text, no fake
 *   progress. A request names the result only; repeated clicks are
 *   prevented, and polling pauses while the tab is hidden.
 */
export function InsightPanel(props: Props) {
  // A new subject starts from a fresh panel (no state carried over).
  return <Panel key={`${props.projectId}:${props.kind}:${props.subjectId}`} {...props} />;
}

type Props = { projectId: string; kind: InsightKind; subjectId: string; canRequest?: boolean };

function Panel({ projectId, kind, subjectId, canRequest = true }: Props) {
  const router = useRouter();
  const [state, setState] = useState<State>({ status: "loading" });
  const [request, setRequest] = useState<RequestState>({ status: "idle" });
  // Only the newest load may update the panel.
  const sequence = useRef(0);

  const authFailure = useCallback(
    (error: unknown): boolean => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return true;
      }
      return false;
    },
    [router],
  );

  useEffect(() => {
    const current = ++sequence.current;
    Promise.all([getAiStatus(), listInsights(projectId, kind, subjectId)])
      .then(([ai, insights]) => {
        if (current === sequence.current) setState({ status: "loaded", ai, insight: insights[0] ?? null });
      })
      .catch((error: unknown) => {
        if (current === sequence.current && !authFailure(error)) setState({ status: "error", error });
      });
  }, [projectId, kind, subjectId, authFailure]);

  const pendingId = state.status === "loaded" && state.insight && (state.insight.status === "QUEUED" || state.insight.status === "RUNNING") ? state.insight.id : null;
  const visible = usePageVisible();
  useEffect(() => {
    if (pendingId === null || !visible) return;
    const current = sequence.current;
    const timer = setTimeout(() => {
      getInsight(projectId, pendingId)
        .then((insight) =>
          setState((now) => (current === sequence.current && now.status === "loaded" && now.insight?.id === insight.id ? { ...now, insight } : now)),
        )
        .catch((error: unknown) => {
          if (!authFailure(error)) setState({ status: "error", error });
        });
    }, INSIGHT_POLL_MS);
    return () => clearTimeout(timer);
  }, [pendingId, visible, state, projectId, authFailure]);

  const submit = useCallback(() => {
    const current = sequence.current;
    setRequest({ status: "sending" });
    requestInsight(projectId, kind, subjectId)
      .then((insight) => {
        if (current !== sequence.current) return;
        setRequest({ status: "idle" });
        setState((now) => (now.status === "loaded" ? { ...now, insight } : now));
      })
      .catch((error: unknown) => {
        if (authFailure(error) || current !== sequence.current) return;
        setRequest(isApiError(error) && PLAN_CODES.includes(error.code) ? { status: "plan" } : { status: "error", error });
      });
  }, [projectId, kind, subjectId, authFailure]);

  if (state.status === "loading") {
    return (
      <div role="status" aria-label="Loading AI explanation" data-testid="insight-loading">
        <Skeleton className="h-20" />
      </div>
    );
  }
  if (state.status === "error") {
    return (
      <div data-testid="insight-error">
        <ApiErrorAlert error={state.error} />
      </div>
    );
  }

  const { ai, insight } = state;
  if (!ai.enabled && insight === null) {
    return (
      <p className="text-muted-foreground text-sm" data-testid="insight-disabled">
        AI explanations are not enabled on this server. Every result above is complete without them.
      </p>
    );
  }

  const busy = request.status === "sending" || insight?.status === "QUEUED" || insight?.status === "RUNNING";
  const allowRequest = canRequest && ai.enabled && (insight === null || insight.status === "FAILED");

  return (
    <Card data-testid="insight-panel" data-kind={kind}>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>
            <h2>{TITLES[kind]}</h2>
          </CardTitle>
          <span className="inline-flex rounded-full border border-violet-300 bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-900" data-testid="insight-ai-label">
            AI-generated · not authoritative
          </span>
        </div>
        <CardDescription>
          Written by a local AI model from the deterministic results on this page, and checked against them before it is shown. It never
          changes a score, level, gap, step or test result; where it and the data differ, the data is right.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {insight === null ? (
          <p className="text-muted-foreground text-sm" data-testid="insight-none">
            No AI explanation has been requested for this result yet.
          </p>
        ) : null}

        {insight?.status === "QUEUED" || insight?.status === "RUNNING" ? (
          <p className="text-sm" role="status" aria-live="polite" data-testid="insight-pending">
            {insight.status === "QUEUED"
              ? "Waiting for the local AI model. This can take a few minutes on a computer without a GPU; this panel updates automatically."
              : "The local AI model is writing the explanation; it is checked against the evidence before it is shown."}
          </p>
        ) : null}

        {insight?.status === "FAILED" ? (
          <div className="grid gap-1 text-sm" data-testid="insight-failed">
            <p className="font-medium">No explanation is available for this result.</p>
            <p className="text-muted-foreground">{insight.failure?.message ?? "The explanation could not be completed."}</p>
          </div>
        ) : null}

        {insight?.status === "SUCCEEDED" && insight.output !== null ? <Explanation insight={insight} /> : null}

        {ai.enabled && !ai.available && allowRequest ? (
          <p className="text-muted-foreground text-sm" data-testid="insight-unavailable">
            The local AI service or its model is not available right now. Try again later.
          </p>
        ) : null}
        {request.status === "plan" ? (
          <p className="text-sm" data-testid="insight-plan">
            AI explanations are not included in the current plan or its allowance is used up.{" "}
            <Link href="/app/billing" className="underline underline-offset-4">
              See billing
            </Link>
          </p>
        ) : null}
        {request.status === "error" ? <ApiErrorAlert error={request.error} /> : null}

        {allowRequest ? (
          <Button className="w-fit" variant="outline" onClick={submit} disabled={busy || !ai.available} data-testid="insight-request">
            {request.status === "sending" ? "Requesting…" : insight?.status === "FAILED" ? "Try again" : "Explain with local AI"}
          </Button>
        ) : null}
      </CardContent>
    </Card>
  );
}

function Explanation({ insight }: { insight: AiInsight }) {
  const output = insight.output!;
  const catalog = new Map(insight.evidence.map((item) => [item.id, item]));
  const sections: { key: string; title: string; claims: InsightClaim[] }[] = [
    { key: "points", title: "What the results show", claims: output.points },
    { key: "next_steps", title: "Suggested next steps", claims: output.next_steps },
  ];

  return (
    <div className="grid gap-4" data-testid="insight-ready">
      <div className="grid gap-1">
        <p data-testid="insight-summary">{output.summary.text}</p>
        <EvidenceRefs refs={output.summary.evidence_refs} catalog={catalog} />
      </div>
      {sections.map((section) =>
        section.claims.length === 0 ? null : (
          <section key={section.key} className="grid gap-2" aria-label={section.title} data-testid={`insight-${section.key}`}>
            <h3 className="text-sm font-semibold">{section.title}</h3>
            <ul className="grid gap-3">
              {section.claims.map((claim, index) => (
                <li key={index} className="grid gap-1">
                  <p className="font-medium">{claim.title}</p>
                  <p className="text-sm">{claim.description}</p>
                  <EvidenceRefs refs={claim.evidence_refs} catalog={catalog} />
                </li>
              ))}
            </ul>
          </section>
        ),
      )}
      <section className="grid gap-2" aria-label="Limitations" data-testid="insight-limitations">
        <h3 className="text-sm font-semibold">Limitations</h3>
        <ul className="grid gap-2">
          {output.limitations.map((limitation, index) => (
            <li key={index} className="grid gap-1 text-sm">
              <p>{limitation.description}</p>
              <EvidenceRefs refs={limitation.evidence_refs} catalog={catalog} />
            </li>
          ))}
        </ul>
      </section>
      <p className="text-muted-foreground text-xs" data-testid="insight-provenance">
        Model {insight.provider.model} ({insight.provider.name}) · insight version {insight.versions.insight}
        {insight.usage.duration_ms !== null ? ` · ${Math.round(insight.usage.duration_ms / 1000)} s` : ""}
      </p>
    </div>
  );
}

/** The evidence a statement cites, resolved to the stored deterministic facts. */
function EvidenceRefs({ refs, catalog }: { refs: string[]; catalog: Map<string, InsightEvidence> }) {
  return (
    <details className="text-muted-foreground text-xs">
      <summary className="cursor-pointer">Evidence ({refs.length})</summary>
      <ul className="mt-1 grid gap-1">
        {refs.map((ref) => {
          const item = catalog.get(ref);
          return (
            <li key={ref} className="rounded border p-2" data-testid={`insight-evidence-${ref}`}>
              <p className="text-foreground font-medium">{item?.label ?? ref}</p>
              {item ? (
                <dl className="grid grid-cols-[auto_1fr] gap-x-2">
                  {Object.entries(item.facts)
                    .filter(([, value]) => value !== null)
                    .map(([key, value]) => (
                      <div key={key} className="contents">
                        <dt>{factLabel(key)}</dt>
                        <dd className="tabular-nums">{formatFact(value)}</dd>
                      </div>
                    ))}
                </dl>
              ) : null}
            </li>
          );
        })}
      </ul>
    </details>
  );
}
