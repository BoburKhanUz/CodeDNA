"use client";

import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { OrganizationFrame, useOrganizationErrors } from "@/components/organizations/organization-frame";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { Organization, OrganizationAnalytics as Analytics } from "@/lib/api/types";
import { getAnalytics } from "@/lib/organizations/client";
import { formatDateTime } from "@/lib/projects/format";

type State = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; analytics: Analytics };

const percent = (score: number | null) => (score === null ? "—" : `${Math.round(score * 100)}`);

/**
 * /app/organizations/[organization]/analytics: deterministic figures over
 * the team's projects. Nothing here is a score of a person; versions are
 * shown side by side and thin evidence is said to be thin.
 */
export function OrganizationAnalytics({ organizationId }: { organizationId: string }) {
  return (
    <OrganizationFrame organizationId={organizationId} title="Analytics">
      {(organization) => <AnalyticsView organization={organization} />}
    </OrganizationFrame>
  );
}

function AnalyticsView({ organization }: { organization: Organization }) {
  const [state, setState] = useState<State>({ status: "loading" });
  const fail = useOrganizationErrors(useCallback((error: unknown) => setState({ status: "error", error }), []));
  useEffect(() => {
    getAnalytics(organization.id)
      .then((analytics) => setState({ status: "ready", analytics }))
      .catch(fail);
  }, [organization.id, fail]);

  if (state.status === "loading") return <p role="status">Loading analytics…</p>;
  if (state.status === "error") return <ApiErrorAlert error={state.error} />;
  const a = state.analytics;

  return (
    <div className="grid gap-6">
      <p className="text-muted-foreground text-sm">
        Read from the latest analysis of each active team project. Personal projects are never included, and no one&apos;s own CodeDNA is changed.
        Averages need at least {a.minimum_projects} projects with measured evidence.
      </p>
      <dl className="grid gap-4 sm:grid-cols-4" data-testid="analytics-summary">
        <Stat label="Active members" value={a.members.active} />
        <Stat label="Active projects" value={a.projects.active} />
        <Stat label="Analyses (30 days)" value={a.analyses.succeeded_last_30_days} />
        <Stat label="Projects with DNA" value={a.dna.projects_with_dna} />
      </dl>
      <p className="text-muted-foreground text-sm" data-testid="analytics-activity">
        {a.analyses.succeeded} completed and {a.analyses.failed} failed analyses in total
        {a.analyses.last_completed_at !== null ? `; last completed ${formatDateTime(a.analyses.last_completed_at)}` : ""}. Seats: {a.seats.used} of{" "}
        {a.seats.limit}.
      </p>

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>CodeDNA across team projects</h2>
          </CardTitle>
          <CardDescription>{a.dna.members_with_dna} current members created a team project with a DNA snapshot.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm" data-testid="analytics-dna">
          {a.dna.by_version.length === 0 ? <p className="text-muted-foreground">No team project has been analysed yet.</p> : null}
          {a.dna.by_version.map((group) => (
            <p key={group.scoring_version}>
              Scoring {group.scoring_version}: {group.projects} {group.projects === 1 ? "project" : "projects"} ·{" "}
              {group.sufficient ? `average overall score ${percent(group.average_overall_score)}` : "not enough evidence for an average"}
            </p>
          ))}
        </CardContent>
      </Card>

      {a.competencies.length === 0 ? (
        <p className="text-muted-foreground text-sm" data-testid="analytics-competencies-empty">
          No skill gap analysis yet: competency figures appear once team projects have been analysed.
        </p>
      ) : null}
      {a.competencies.map((group) => (
        <Card key={`${group.competency_version}|${group.skill_gap_version}|${group.target_profile}|${group.target_profile_version}`} data-testid="analytics-competencies">
          <CardHeader>
            <CardTitle>
              <h2>Competencies</h2>
            </CardTitle>
            <CardDescription>
              Competency {group.competency_version}, skill gaps {group.skill_gap_version}, target {group.target_profile} {group.target_profile_version} ·{" "}
              {group.projects} {group.projects === 1 ? "project" : "projects"}
            </CardDescription>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-muted-foreground">
                <tr>
                  <th scope="col" className="py-1 font-medium">Competency</th>
                  <th scope="col" className="py-1 font-medium">Measured in</th>
                  <th scope="col" className="py-1 font-medium">Average</th>
                  <th scope="col" className="py-1 font-medium">Projects with a gap</th>
                </tr>
              </thead>
              <tbody>
                {group.competencies.map((c) => (
                  <tr key={c.key} className="border-t" data-testid="analytics-competency" data-sufficient={c.sufficient}>
                    <th scope="row" className="py-2 pr-2 font-normal">
                      {c.key}
                    </th>
                    <td className="py-2 pr-2">{c.measured_projects}</td>
                    <td className="py-2 pr-2">{c.sufficient ? percent(c.average_score) : "Not enough evidence"}</td>
                    <td className="py-2">
                      {c.gap_projects}
                      {c.priorities.HIGH > 0 ? ` (${c.priorities.HIGH} high priority)` : ""}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border p-4">
      <dt className="text-muted-foreground text-sm">{label}</dt>
      <dd className="text-2xl font-semibold">{value}</dd>
    </div>
  );
}
