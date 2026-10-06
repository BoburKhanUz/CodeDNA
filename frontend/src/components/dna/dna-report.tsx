import Link from "next/link";

import { ScoreBar } from "@/components/dna/score-bar";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { DnaComponent, DnaDimension, DnaMetricEvidence, DnaSnapshot } from "@/lib/api/types";
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
import { formatDateTime, languageLabel } from "@/lib/projects/format";

/**
 * One DNA snapshot, as returned by the API. Every number shown here is a
 * backend value, reformatted for display only.
 */
export function DnaReport({ snapshot, projectId }: { snapshot: DnaSnapshot; projectId: string }) {
  return (
    <div className="grid gap-6">
      <div className="grid gap-4 md:grid-cols-2">
        <OverallScoreCard snapshot={snapshot} />
        <DataQualityCard snapshot={snapshot} />
      </div>

      <section aria-labelledby="dna-dimensions" className="grid gap-4">
        <div className="grid gap-1">
          <h2 id="dna-dimensions" className="text-lg font-semibold tracking-tight">
            Dimensions
          </h2>
          <p className="text-muted-foreground text-sm">
            Each dimension is the weighted result of the measurements below. Open a dimension to see the evidence it used.
          </p>
        </div>
        <div className="grid items-start gap-4 lg:grid-cols-3">
          {snapshot.dimensions.map((dimension) => (
            <DimensionCard key={dimension.dimension} dimension={dimension} />
          ))}
        </div>
      </section>

      <SourceCard snapshot={snapshot} projectId={projectId} />
    </div>
  );
}

function OverallScoreCard({ snapshot }: { snapshot: DnaSnapshot }) {
  const score = snapshot.status === "READY" ? formatScore(snapshot.overall_score) : null;
  const unavailable = snapshot.dimensions.filter((dimension) => dimension.status === "UNAVAILABLE");

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>CodeDNA score</h2>
        </CardTitle>
        <CardDescription>Weighted result of the scored dimensions, on a scale of 0 to 100.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {score !== null ? (
          <>
            <p className="flex items-baseline gap-2" data-testid="dna-overall-score">
              <span className="text-5xl font-semibold tracking-tight tabular-nums">{score}</span>
              <span className="text-muted-foreground text-lg">/ 100</span>
            </p>
            <ScoreBar value={snapshot.overall_score} label="CodeDNA score" />
            {snapshot.aggregation?.renormalized ? (
              <p className="text-muted-foreground text-sm">
                Calculated from {snapshot.aggregation.scored_dimensions.length} of {snapshot.dimensions.length} dimensions;{" "}
                {unavailable.map((dimension) => dimension.name).join(", ")} had too little evidence and their weight was
                shared among the others.
              </p>
            ) : null}
          </>
        ) : (
          <div className="grid gap-2" data-testid="dna-overall-score">
            <p className="text-3xl font-semibold tracking-tight">Insufficient data</p>
            <p className="text-muted-foreground text-sm">
              The analyzed source did not contain enough supported evidence to calculate an overall score
              {snapshot.aggregation?.minimum_scored_dimensions
                ? ` (at least ${snapshot.aggregation.minimum_scored_dimensions} dimensions must be scored; ${snapshot.aggregation.scored_dimensions.length} could be)`
                : ""}
              . This is not a score of 0.
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function DataQualityCard({ snapshot }: { snapshot: DnaSnapshot }) {
  const quality = formatPercent(snapshot.data_quality);
  const breakdown = snapshot.data_quality_breakdown;

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Data quality</h2>
        </CardTitle>
        <CardDescription>
          Based on the availability and completeness of analyzed code evidence. It describes the input, not how certain
          the score is.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        <p className="text-3xl font-semibold tracking-tight tabular-nums" data-testid="dna-data-quality">
          {quality ?? "Not recorded"}
        </p>
        <ScoreBar value={snapshot.data_quality} label="Data quality" />
        {breakdown ? (
          <details className="text-sm">
            <summary className="cursor-pointer font-medium">How data quality is calculated</summary>
            <dl className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto]">
              <dt className="text-muted-foreground">
                Parse coverage: {formatCount(breakdown.parse_coverage.files_parsed)} of{" "}
                {formatCount(breakdown.parse_coverage.files_analyzable)} analyzable files parsed
              </dt>
              <dd className="tabular-nums">{formatPercent(breakdown.parse_coverage.value) ?? "—"}</dd>
              <dd className="text-muted-foreground">weight {formatWeight(breakdown.parse_coverage.weight) ?? "—"}</dd>
              <dt className="text-muted-foreground">
                Evidence volume: {formatCount(breakdown.evidence_volume.functions)} functions (full at{" "}
                {formatCount(breakdown.evidence_volume.target)})
              </dt>
              <dd className="tabular-nums">{formatPercent(breakdown.evidence_volume.value) ?? "—"}</dd>
              <dd className="text-muted-foreground">weight {formatWeight(breakdown.evidence_volume.weight) ?? "—"}</dd>
              <dt className="text-muted-foreground">
                Measurement availability: {formatCount(breakdown.metric_availability.available_components)} of{" "}
                {formatCount(breakdown.metric_availability.components)} measurements available
              </dt>
              <dd className="tabular-nums">{formatPercent(breakdown.metric_availability.value) ?? "—"}</dd>
              <dd className="text-muted-foreground">weight {formatWeight(breakdown.metric_availability.weight) ?? "—"}</dd>
            </dl>
          </details>
        ) : null}
      </CardContent>
    </Card>
  );
}

function DimensionCard({ dimension }: { dimension: DnaDimension }) {
  const score = formatScore(dimension.score);
  const scored = dimension.status === "SCORED" && score !== null;

  return (
    <Card className="gap-4" data-testid={`dna-dimension-${dimension.dimension}`}>
      <CardHeader>
        <CardTitle>
          <h3>{dimension.name}</h3>
        </CardTitle>
        {dimension.description ? <CardDescription>{dimension.description}</CardDescription> : null}
      </CardHeader>
      <CardContent className="grid gap-4">
        <div className="grid gap-2">
          <p className="text-3xl font-semibold tracking-tight tabular-nums">
            {scored ? score : <span className="text-xl">Not scored</span>}
          </p>
          <ScoreBar value={scored ? dimension.score : null} label={`${dimension.name} score`} />
          {!scored ? (
            <p className="text-muted-foreground text-sm">
              {evidenceStatusLabel(dimension.unavailable_reason)}: this dimension is not part of the overall score.
            </p>
          ) : null}
        </div>
        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted-foreground">Weight</dt>
          <dd className="tabular-nums">{formatWeight(dimension.weight) ?? "—"}</dd>
          {dimension.effective_weight !== null && dimension.effective_weight !== dimension.weight ? (
            <>
              <dt className="text-muted-foreground">Weight after redistribution</dt>
              <dd className="tabular-nums">{formatWeight(dimension.effective_weight)}</dd>
            </>
          ) : null}
          <dt className="text-muted-foreground">Contribution</dt>
          <dd className="tabular-nums">{dimension.contribution !== null ? `${formatScore(dimension.contribution)} points` : "—"}</dd>
          <dt className="text-muted-foreground">Data quality</dt>
          <dd className="tabular-nums">{formatPercent(dimension.data_quality) ?? "—"}</dd>
        </dl>
        <details className="text-sm">
          <summary className="cursor-pointer font-medium">Evidence ({dimension.components.length} measurements)</summary>
          <ul className="mt-3 grid gap-3">
            {dimension.components.map((component) => (
              <ComponentEvidence key={component.key} component={component} />
            ))}
          </ul>
        </details>
      </CardContent>
    </Card>
  );
}

function ComponentEvidence({ component }: { component: DnaComponent }) {
  const available = component.status === "AVAILABLE";

  return (
    <li className="grid gap-1 rounded-lg border p-3" data-testid={`dna-component-${component.key}`}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="font-medium">{componentLabel(component.key)}</span>
        <span className={available ? "text-muted-foreground text-xs" : "text-xs font-medium"}>{evidenceStatusLabel(component.status)}</span>
      </div>
      {component.description ? <p className="text-muted-foreground text-xs">{component.description}</p> : null}
      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-0.5">
        {uniqueMetrics(component).map((metric) => (
          <MetricRow key={metric.metric} metric={metric} />
        ))}
        {available ? (
          <>
            <dt className="text-muted-foreground">Measured value</dt>
            <dd className="tabular-nums">{component.share ? formatPercent(component.value) : formatDecimal(component.value)}</dd>
            <dt className="text-muted-foreground">Component score</dt>
            <dd className="tabular-nums">{formatScore(component.score)}</dd>
          </>
        ) : null}
        <dt className="text-muted-foreground">Weight in dimension</dt>
        <dd className="tabular-nums">
          {formatWeight(component.weight) ?? "—"}
          {component.required === false ? " (optional)" : ""}
        </dd>
      </dl>
      {component.status === "INSUFFICIENT_EVIDENCE" && component.minimum_denominator !== null ? (
        <p className="text-muted-foreground text-xs">
          Needs at least {component.minimum_denominator} {component.denominator.map((m) => metricLabel(m.metric).toLowerCase()).join(" + ")}{" "}
          to be measured.
        </p>
      ) : null}
      {available && component.best !== null && component.worst !== null ? (
        <p className="text-muted-foreground text-xs">
          Scores 100 at {component.share ? formatPercent(component.best) : formatDecimal(component.best)} or less and 0 at{" "}
          {component.share ? formatPercent(component.worst) : formatDecimal(component.worst)} or more.
        </p>
      ) : null}
    </li>
  );
}

/** Each measured input once: a count can appear in both the numerator and the denominator (e.g. files with syntax errors). */
function uniqueMetrics(component: DnaComponent): DnaMetricEvidence[] {
  const seen = new Set<string>();
  return [...component.numerator, ...component.denominator].filter((metric) => !seen.has(metric.metric) && seen.add(metric.metric));
}

function MetricRow({ metric }: { metric: DnaMetricEvidence }) {
  return (
    <>
      <dt className="text-muted-foreground">{metricLabel(metric.metric)}</dt>
      <dd className="tabular-nums">{metric.value === null ? "Not available" : formatCount(metric.value)}</dd>
    </>
  );
}

function SourceCard({ snapshot, projectId }: { snapshot: DnaSnapshot; projectId: string }) {
  const source = snapshot.source_snapshot;
  const run = snapshot.analysis_run;

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Source and calculation</h2>
        </CardTitle>
        <CardDescription>What was analyzed, and how this assessment was produced.</CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-3 text-sm sm:grid-cols-[12rem_1fr]">
          <dt className="text-muted-foreground">Source snapshot</dt>
          <dd>
            {source ? `v${source.version}, ${formatCount(source.file_count)} files, uploaded ${formatDateTime(source.created_at)}` : "—"}
          </dd>
          {source?.primary_language ? (
            <>
              <dt className="text-muted-foreground">Primary language</dt>
              <dd>{languageLabel(source.primary_language)}</dd>
            </>
          ) : null}
          {snapshot.data_quality_breakdown ? (
            <>
              <dt className="text-muted-foreground">Files analyzed</dt>
              <dd>
                {formatCount(snapshot.data_quality_breakdown.parse_coverage.files_parsed)} parsed of{" "}
                {formatCount(snapshot.data_quality_breakdown.parse_coverage.files_analyzable)} analyzable
              </dd>
            </>
          ) : null}
          <dt className="text-muted-foreground">Analysis run</dt>
          <dd>
            {run ? (
              <>
                <code className="font-mono text-xs">{run.id}</code> · {run.status} · completed {formatDateTime(run.completed_at)}
              </>
            ) : (
              "—"
            )}
          </dd>
          <dt className="text-muted-foreground">Calculated</dt>
          <dd>{formatDateTime(snapshot.created_at)}</dd>
          <dt className="text-muted-foreground">Scoring version</dt>
          <dd>
            {snapshot.scoring_version}
            {snapshot.versions.metrics ? ` (metrics ${snapshot.versions.metrics}, analyzer ${snapshot.versions.analyzer ?? "—"})` : ""}
          </dd>
          {snapshot.specification_fingerprint ? (
            <>
              <dt className="text-muted-foreground">Specification fingerprint</dt>
              <dd>
                <code className="font-mono text-xs break-all">{snapshot.specification_fingerprint}</code>
              </dd>
            </>
          ) : null}
          <dt className="text-muted-foreground">Result hash</dt>
          <dd>
            <code className="font-mono text-xs break-all">{snapshot.result_hash}</code>
          </dd>
        </dl>
        <p className="mt-4 text-sm">
          <Link href={`/app/projects/${projectId}`} className="underline underline-offset-4">
            Project and source snapshots
          </Link>
        </p>
      </CardContent>
    </Card>
  );
}
