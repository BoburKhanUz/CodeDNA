import { render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { GrowthView } from "@/components/growth/growth-view";
import type { GrowthOverview, GrowthSnapshot, Project } from "@/lib/api/types";
import { formatDelta } from "@/lib/growth/format";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { compared, incomparable, insufficient, notEstablished, NOTICE, OLD_GROWTH_ID, overview, summaryOf, unchanged } from "@/test/growth";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

interface Scenario {
  growth: GrowthOverview;
  timeline?: GrowthSnapshot[];
  snapshots?: GrowthSnapshot[];
  projectOverride?: Partial<Project>;
}

function respondWith({ growth, timeline, snapshots = [], projectOverride = {} }: Scenario) {
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    const detail = /\/growth\/([0-9a-z]{26})$/.exec(url);
    if (detail) {
      const found = [...snapshots, ...(growth.latest ? [growth.latest] : [])].find((s) => s.id === detail[1]);
      return found ? jsonResponse({ data: found }) : apiErrorResponse(404, "RESOURCE_NOT_FOUND");
    }
    if (url.includes("/growth/timeline")) return jsonResponse(page((timeline ?? (growth.latest ? [growth.latest] : [])).map(summaryOf)));
    if (url.includes("/growth")) return jsonResponse({ data: growth });
    return jsonResponse({ data: { ...project, ...projectOverride } });
  });
}

function methods(): string[] {
  return fetchMock.mock.calls.map(([, init]) => init?.method ?? "GET");
}

describe("GrowthView", () => {
  it("shows a loading state, then growth with the notice and links to related pages", async () => {
    respondWith({ growth: overview(compared()) });
    render(<GrowthView projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading growth" })).toBeInTheDocument();
    expect(await screen.findByTestId("growth-notice")).toHaveTextContent(NOTICE);
    expect(screen.getByRole("heading", { level: 1, name: "Growth" })).toBeInTheDocument();
    const nav = screen.getByRole("navigation", { name: "Related pages" });
    for (const [name, path] of [["CodeDNA", "dna"], ["Competency Matrix", "competencies"], ["Skill Gaps", "skill-gaps"], ["Learning Roadmap", "roadmap"]]) {
      expect(within(nav).getByRole("link", { name })).toHaveAttribute("href", `/app/projects/${project.id}/${path}`);
    }
    // Read-only: the page only ever reads.
    expect(new Set(methods())).toEqual(new Set(["GET"]));
  });

  it("states that there is no assessment yet, without zeros", async () => {
    respondWith({ growth: overview(null) });
    render(<GrowthView projectId={project.id} />);

    const state = await screen.findByTestId("growth-state");
    expect(state).toHaveAttribute("data-state", "NO_ASSESSMENT");
    expect(state).toHaveTextContent("No assessment yet");
    expect(screen.queryByTestId("growth-observation")).not.toBeInTheDocument();
    expect(screen.queryByTestId("growth-timeline")).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/\b0\.00\b|\+0/);
  });

  it("says the baseline is not established after one assessment and shows no change", async () => {
    respondWith({ growth: overview(notEstablished()) });
    render(<GrowthView projectId={project.id} />);

    const state = await screen.findByTestId("growth-state");
    expect(state).toHaveTextContent("Baseline not established");
    expect(state).toHaveTextContent("It is not treated as zero.");
    expect(screen.queryByTestId("growth-summary")).not.toBeInTheDocument();
    expect(screen.queryByTestId("growth-observation")).not.toBeInTheDocument();
    expect(within(screen.getByTestId("growth-assessments")).getAllByText("Baseline not established")).toHaveLength(1);
    expect(screen.getByTestId("growth-activity")).toHaveTextContent("After the next code assessment");
    expect(screen.getByTestId("growth-timeline")).toHaveTextContent("Baseline not established");
  });

  it("shows changes, before and after values on the full scale, levels and categorical counts", async () => {
    respondWith({ growth: overview(compared()) });
    render(<GrowthView projectId={project.id} />);

    expect(await screen.findByTestId("growth-state")).toHaveTextContent("Changes since the previous assessment");
    const overall = screen.getAllByTestId("growth-observation").find((el) => el.dataset.metric === "DNA:OVERALL");
    expect(overall).toBeDefined();
    expect(overall).toHaveTextContent("CodeDNA overall score");
    expect(overall).toHaveTextContent("Improved");
    expect(within(overall as HTMLElement).getByRole("meter", { name: "CodeDNA overall score, previous" })).toHaveAttribute("aria-valuemax", "100");
    expect(within(overall as HTMLElement).getByRole("meter", { name: "CodeDNA overall score, previous" })).toHaveAttribute("aria-valuenow", "30.5");
    expect(within(overall as HTMLElement).getByRole("meter", { name: "CodeDNA overall score, latest" })).toHaveAttribute("aria-valuenow", "80.5");
    expect(within(overall as HTMLElement).getByTestId("growth-delta")).toHaveTextContent("Difference: +50.00 points of 100");

    const competency = screen.getAllByTestId("growth-observation").find((el) => el.dataset.metric === "COMPETENCY:FUNCTION_DESIGN") as HTMLElement;
    expect(competency).toHaveTextContent("Level: Developing → Established (rose)");

    const gap = screen.getAllByTestId("growth-observation").find((el) => el.dataset.metric === "SKILL_GAP:FUNCTION_DESIGN") as HTMLElement;
    expect(gap).toHaveTextContent("Material gap → No material gap");
    expect(gap).toHaveTextContent("−17.00 points of 100 (gap size)");
    expect(gap).toHaveAttribute("data-status", "IMPROVED");
    const hygiene = screen.getAllByTestId("growth-observation").find((el) => el.dataset.metric === "SKILL_GAP:CODE_HYGIENE") as HTMLElement;
    expect(hygiene).toHaveTextContent("Regressed");

    const events = screen.getByTestId("growth-events");
    expect(events).toHaveTextContent("Skill gap · Function design: the material gap closed (−17.00 points)");
    expect(events).toHaveTextContent("Competency · Function design: level rose from Developing to Established");
    expect(events).toHaveTextContent("Skill gap · Code hygiene: regressed (+6.00 points)");

    const summary = screen.getByTestId("growth-summary");
    const rows = within(summary).getAllByRole("row");
    expect(rows[1]).toHaveTextContent("CodeDNA1011");
    expect(rows[3]).toHaveTextContent("Skill gaps1100");
    expect(summary).toHaveTextContent("Competency levels: 1 rose, 0 fell.");
    // No combined growth score anywhere.
    expect(document.body.textContent).not.toMatch(/growth score:|overall growth|total growth/i);
  });

  it("never shows a value or zero for unmeasured evidence", async () => {
    respondWith({ growth: overview(compared()) });
    render(<GrowthView projectId={project.id} />);

    const unmeasured = (await screen.findAllByTestId("growth-observation")).find((el) => el.dataset.metric === "COMPETENCY:TYPE_STRUCTURE") as HTMLElement;
    expect(unmeasured).toHaveTextContent("Insufficient evidence");
    expect(within(unmeasured).getByTestId("growth-no-delta")).toHaveTextContent("never treated as zero");
    expect(within(unmeasured).queryByRole("meter")).not.toBeInTheDocument();
    expect(within(unmeasured).queryByTestId("growth-delta")).not.toBeInTheDocument();
    const dimension = screen.getAllByTestId("growth-observation").find((el) => el.dataset.metric === "DNA:STRUCTURE") as HTMLElement;
    expect(dimension).toHaveTextContent("Unavailable → Scored");
  });

  it("shows no fake delta when the versions differ", async () => {
    respondWith({ growth: overview(incomparable()) });
    render(<GrowthView projectId={project.id} />);

    const state = await screen.findByTestId("growth-state");
    expect(state).toHaveTextContent("No comparable assessment");
    expect(state).toHaveTextContent("This is not a regression.");
    expect(screen.getByTestId("growth-differences")).toHaveTextContent("Competency version: 0.9.0 → 1.0.0");
    expect(screen.queryByTestId("growth-observation")).not.toBeInTheDocument();
    expect(screen.queryByTestId("growth-delta")).not.toBeInTheDocument();
    expect(screen.queryByTestId("growth-summary")).not.toBeInTheDocument();
    expect(screen.queryByTestId("growth-events")).not.toBeInTheDocument();
    expect(screen.queryByTestId("growth-dna")).not.toBeInTheDocument();
    expect(screen.getByTestId("growth-timeline-item")).toHaveTextContent("No comparable assessment");
  });

  it("says when no meaningful changes were detected", async () => {
    respondWith({ growth: overview(unchanged()) });
    render(<GrowthView projectId={project.id} />);

    expect(await screen.findByTestId("growth-state")).toHaveTextContent("No meaningful changes detected");
    expect(screen.getByTestId("growth-events")).toHaveTextContent("No meaningful changes detected.");
  });

  it("says when the evidence is insufficient, never a regression", async () => {
    respondWith({ growth: overview(insufficient()) });
    render(<GrowthView projectId={project.id} />);

    const state = await screen.findByTestId("growth-state");
    expect(state).toHaveTextContent("Insufficient evidence");
    expect(state).toHaveTextContent("this is not a regression");
  });

  it("reports an assessment whose growth is not calculated yet", async () => {
    respondWith({ growth: overview(notEstablished(), { state: "NOT_CALCULATED" }) });
    render(<GrowthView projectId={project.id} />);

    expect(await screen.findByTestId("growth-state")).toHaveTextContent("Growth not calculated yet");
  });

  it("keeps learning activity apart, as context, without causal language", async () => {
    respondWith({ growth: overview(compared()) });
    render(<GrowthView projectId={project.id} />);

    const activity = await screen.findByTestId("growth-activity");
    expect(activity).toHaveTextContent("Learning activity (context only)");
    expect(activity).toHaveTextContent("Between these two assessments: 3 learning steps completed and 1 challenge passed.");
    expect(activity).toHaveTextContent("Learning activity is not growth evidence");
    expect(document.body.textContent).not.toMatch(/because you|thanks to|as a result of|due to your/i);
    // The activity is not part of any change or count.
    expect(screen.getByTestId("growth-summary")).not.toHaveTextContent(/learning|challenge/i);
    expect(screen.getByTestId("growth-events")).not.toHaveTextContent(/learning|challenge/i);
  });

  it("draws a trend only for three or more comparable assessments, on the full scale", async () => {
    const series = [
      { metric_type: "DNA" as const, metric_key: "OVERALL", points: [{ assessed_at: "2026-10-01T12:00:00Z", value: "0.3050" }, { assessed_at: "2026-10-07T12:00:00Z", value: "0.8050" }] },
    ];
    respondWith({ growth: overview(compared(), { series }) });
    const { unmount } = render(<GrowthView projectId={project.id} />);
    await screen.findByTestId("growth-state");
    expect(screen.queryByTestId("growth-trend")).not.toBeInTheDocument();
    unmount();

    series[0].points.push({ assessed_at: "2026-10-08T12:00:00Z", value: "0.7000" });
    respondWith({ growth: overview(compared(), { series }) });
    render(<GrowthView projectId={project.id} />);
    const line = await screen.findByTestId("growth-sparkline");
    expect(within(line).getByRole("img")).toHaveAccessibleName("CodeDNA: CodeDNA overall score: 30.50, 80.50, 70.00 of 100");
    expect(line.querySelector("svg")).toHaveAttribute("viewBox", "0 0 200 48");
    expect(line.querySelector("polyline")).toHaveAttribute("points", "0.0,33.4 100.0,9.4 200.0,14.4");
  });

  it("lists the timeline newest first and opens an earlier snapshot as superseded", async () => {
    const latest = compared();
    const old = notEstablished();
    respondWith({ growth: overview(latest), timeline: [latest, old], snapshots: [old] });
    render(<GrowthView projectId={project.id} snapshotId={OLD_GROWTH_ID} />);

    expect(await screen.findByTestId("growth-superseded")).toHaveTextContent("A newer assessment exists.");
    expect(screen.getByTestId("growth-state")).toHaveTextContent("Baseline not established");
    const items = screen.getAllByTestId("growth-timeline-item");
    expect(items[0]).toHaveTextContent("3 improved · 1 regressed");
    expect(items[1]).toHaveTextContent("(shown)");
    expect(items[1]).toHaveTextContent("Baseline not established");
    expect(within(items[1]).getByRole("link")).toHaveAttribute("href", `/app/projects/${project.id}/growth?snapshot=${OLD_GROWTH_ID}`);
    expect(within(items[1]).getByRole("link")).toHaveAttribute("aria-current", "page");
  });

  it("shows provenance: rules, versions and lineage", async () => {
    respondWith({ growth: overview(compared({ rules: { version: "1.0.0", fingerprint: "f".repeat(64), current: false } })) });
    render(<GrowthView projectId={project.id} />);

    const provenance = await screen.findByTestId("growth-provenance");
    expect(provenance).toHaveTextContent("Growth rules 1.0.0 (earlier rules than the server now uses)");
    expect(provenance).toHaveTextContent("f".repeat(64));
    expect(provenance).toHaveTextContent("Target profileENGINEERING_STANDARDENGINEERING_STANDARD");
    expect(provenance).toHaveTextContent(compared().current.analysis_run_id);
  });

  it("keeps archived projects readable", async () => {
    respondWith({ growth: overview(compared()), projectOverride: { status: "ARCHIVED" } });
    render(<GrowthView projectId={project.id} />);

    expect(await screen.findByTestId("growth-archived")).toHaveTextContent("growth history stays readable");
    expect(screen.getAllByTestId("growth-observation").length).toBeGreaterThan(0);
  });

  it("shows not found for invalid IDs without a request, and for other users' projects", async () => {
    const first = render(<GrowthView projectId="../../etc" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    first.unmount();
    const second = render(<GrowthView projectId={project.id} snapshotId="x?y" />);
    expect(screen.getByRole("heading", { name: "Growth record not found" })).toBeInTheDocument();
    second.unmount();
    expect(fetchMock).not.toHaveBeenCalled();

    fetchMock.mockImplementation(async () => apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const third = render(<GrowthView projectId={project.id} />);
    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    third.unmount();

    respondWith({ growth: overview(compared()) });
    render(<GrowthView projectId={project.id} snapshotId={"01k6r0a1b2c3d4e5f6g7h8j9zz"} />);
    expect(await screen.findByRole("heading", { name: "Growth record not found" })).toBeInTheDocument();
  });

  it("redirects to login on 401 and offers a retry on errors", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    const { unmount } = render(<GrowthView projectId={project.id} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
    unmount();

    fetchMock.mockImplementation(async () => apiErrorResponse(500, "INTERNAL_ERROR"));
    render(<GrowthView projectId={project.id} />);
    expect(await screen.findByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});

describe("formatDelta", () => {
  it("formats signed deltas digit by digit", () => {
    expect(formatDelta("0.1900")).toBe("+19.00");
    expect(formatDelta("-0.0040")).toBe("−0.40");
    expect(formatDelta("0.0000")).toBe("0.00");
    expect(formatDelta("-1.0000")).toBe("−100.00");
    expect(formatDelta(null)).toBeNull();
    expect(formatDelta("abc")).toBeNull();
  });
});

