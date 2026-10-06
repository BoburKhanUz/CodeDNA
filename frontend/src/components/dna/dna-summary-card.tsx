"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { ScoreBar } from "@/components/dna/score-bar";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import type { DnaSnapshotSummary } from "@/lib/api/types";
import { listDnaSnapshots } from "@/lib/dna/client";
import { formatPercent, formatScore } from "@/lib/dna/format";

type State = { status: "loading" } | { status: "error" } | { status: "ready"; latest: DnaSnapshotSummary | null };

/** The newest CodeDNA assessment on the project page, linking to the dashboard. */
export function DnaSummaryCard({ projectId }: { projectId: string }) {
  const [state, setState] = useState<State>({ status: "loading" });

  useEffect(() => {
    let active = true;
    listDnaSnapshots(projectId, 1, 1)
      .then((page) => active && setState({ status: "ready", latest: page.data[0] ?? null }))
      .catch(() => active && setState({ status: "error" }));
    return () => {
      active = false;
    };
  }, [projectId]);

  const latest = state.status === "ready" ? state.latest : null;
  const score = latest?.status === "READY" ? formatScore(latest.overall_score) : null;

  return (
    <Card data-testid="dna-summary">
      <CardHeader>
        <CardTitle>
          <h2>CodeDNA</h2>
        </CardTitle>
        <CardDescription>Deterministic analysis of the analyzed source code.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {state.status === "loading" ? (
          <Skeleton className="h-10 w-40" aria-label="Loading CodeDNA" />
        ) : state.status === "error" ? (
          <p className="text-muted-foreground text-sm">The CodeDNA assessment could not be loaded.</p>
        ) : latest === null ? (
          <p className="text-muted-foreground text-sm">No assessment available.</p>
        ) : (
          <div className="grid gap-2">
            <p className="flex items-baseline gap-2">
              <span className="text-3xl font-semibold tracking-tight tabular-nums">{score ?? "Insufficient data"}</span>
              {score !== null ? <span className="text-muted-foreground">/ 100</span> : null}
            </p>
            {score !== null ? <ScoreBar value={latest.overall_score} label="CodeDNA score" /> : null}
            <p className="text-muted-foreground text-sm">Data quality: {formatPercent(latest.data_quality) ?? "—"}</p>
          </div>
        )}
        <Link href={`/app/projects/${projectId}/dna`} className="w-fit text-sm font-medium underline underline-offset-4">
          {latest === null ? "View details →" : "View CodeDNA →"}
        </Link>
      </CardContent>
    </Card>
  );
}
