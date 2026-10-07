import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { HistoryView } from "@/components/history/history-view";
import type { HistoryComparison, HistoryPoint, Project } from "@/lib/api/types";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { COMMIT, comparison, id, NOTICE, point, threeCompatible } from "@/test/history";
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
  points: HistoryPoint[];
  meta?: Partial<{ current_page: number; per_page: number; total: number; last_page: number }>;
  compare?: HistoryComparison | Response;
  projectOverride?: Partial<Project>;
  history?: Response;
}

function respondWith({ points, meta, compare, projectOverride = {}, history }: Scenario) {
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    if (url.includes("/history/compare")) {
      if (compare instanceof Response) return compare;
      return jsonResponse({ data: compare ?? comparison() });
    }
    if (url.includes("/history")) return history ?? jsonResponse(page(points, meta));
    return jsonResponse({ data: { ...project, ...projectOverride } });
  });
}

function urls(): string[] {
  return fetchMock.mock.calls.map(([input]) => String(input));
}

describe("HistoryView", () => {
  it("loads, shows the notice and navigation, and only reads", async () => {
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading historical DNA" })).toBeInTheDocument();
    expect(await screen.findByTestId("history-notice")).toHaveTextContent(NOTICE);
    expect(screen.getByRole("heading", { level: 1, name: "Historical DNA" })).toBeInTheDocument();
    const nav = screen.getByRole("navigation", { name: "Related pages" });
    for (const [name, path] of [["CodeDNA", "dna"], ["Growth", "growth"], ["Competency Matrix", "competencies"], ["Skill Gaps", "skill-gaps"]]) {
      expect(within(nav).getByRole("link", { name })).toHaveAttribute("href", `/app/projects/${project.id}/${path}`);
    }
    expect(urls()).toContain(`/api/v1/projects/${project.id}/history?page=1&per_page=25`);
    expect(new Set(fetchMock.mock.calls.map(([, init]) => init?.method ?? "GET"))).toEqual(new Set(["GET"]));
  });

  it("shows an empty state without values or a chart", async () => {
    respondWith({ points: [] });
    render(<HistoryView projectId={project.id} />);

    expect(await screen.findByTestId("history-empty")).toHaveTextContent("No assessments yet");
    expect(screen.queryByTestId("history-chart")).not.toBeInTheDocument();
    expect(screen.queryByTestId("history-timeline")).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/\b0\.00\b/);
  });

  it("shows one assessment as a baseline, with no trend line", async () => {
    respondWith({ points: [point({ n: 1, analyzedAt: "2026-10-01T10:00:00Z" })] });
    render(<HistoryView projectId={project.id} />);

    expect(await screen.findByTestId("history-baseline-message")).toHaveTextContent("Baseline established");
    expect(screen.getByTestId("history-baseline")).toHaveTextContent("first assessment");
    expect(screen.queryAllByTestId("history-line")).toHaveLength(0);
    expect(screen.getAllByTestId("history-marker").length).toBeGreaterThan(0);
    expect(screen.getAllByTestId("history-item")).toHaveLength(1);
  });

  it("draws one line per dimension across compatible assessments, with a legend", async () => {
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-chart");
    const lines = screen.getAllByTestId("history-line");
    expect(lines.map((l) => l.getAttribute("data-series"))).toEqual(["COMPLEXITY", "STRUCTURE", "CODE_HYGIENE"]);
    // Oldest on the left: Complexity 61.00 → 74.00 → 79.00 on a fixed 0–100 axis.
    expect(lines[0].getAttribute("points")?.split(" ")).toHaveLength(3);
    expect(within(screen.getByTestId("history-legend")).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Complexity", "Structure", "Code hygiene"]);
    expect(screen.queryByTestId("history-segment-divider")).not.toBeInTheDocument();
    expect(screen.queryByTestId("history-baseline-message")).not.toBeInTheDocument();
    expect(screen.getAllByTestId("history-segment")).toHaveLength(1);
  });

  it("never connects points across scoring versions", async () => {
    respondWith({
      points: [
        point({ n: 4, analyzedAt: "2026-10-04T10:00:00Z", scoringVersion: "2.0.0", segment: "seg-v2" }),
        point({ n: 3, analyzedAt: "2026-10-03T10:00:00Z", scoringVersion: "2.0.0", segment: "seg-v2" }),
        point({ n: 2, analyzedAt: "2026-10-02T10:00:00Z" }),
        point({ n: 1, analyzedAt: "2026-10-01T10:00:00Z" }),
      ],
    });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-chart");
    // Two segments of two points: two 2-point lines per series, never one 4-point line.
    const lines = screen.getAllByTestId("history-line");
    expect(lines).toHaveLength(6);
    for (const line of lines) expect(line.getAttribute("points")?.split(" ")).toHaveLength(2);
    expect(screen.getAllByTestId("history-segment-divider")).toHaveLength(1);
    expect(screen.getAllByTestId("history-segment").map((s) => s.textContent)).toEqual([
      expect.stringContaining("Scoring v2.0.0"),
      expect.stringContaining("Scoring v1.0.0"),
    ]);
  });

  it("states that no trend is available when no two comparable assessments exist", async () => {
    respondWith({
      points: [point({ n: 2, analyzedAt: "2026-10-02T10:00:00Z", scoringVersion: "2.0.0", segment: "seg-v2" }), point({ n: 1, analyzedAt: "2026-10-01T10:00:00Z" })],
    });
    render(<HistoryView projectId={project.id} />);

    expect(await screen.findByTestId("history-no-trend")).toHaveTextContent("No historical trend available");
    expect(screen.queryAllByTestId("history-line")).toHaveLength(0);
  });

  it("breaks a line at a missing value and never shows it as zero", async () => {
    respondWith({
      points: [
        point({ n: 3, analyzedAt: "2026-10-03T10:00:00Z" }),
        point({ n: 2, analyzedAt: "2026-10-02T10:00:00Z", scores: ["0.7000", null, "0.9000"], overall: null }),
        point({ n: 1, analyzedAt: "2026-10-01T10:00:00Z" }),
      ],
    });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-chart");
    expect(screen.getAllByTestId("history-line").map((l) => l.getAttribute("data-series"))).toEqual(["COMPLEXITY", "CODE_HYGIENE"]);
    const item = screen.getAllByTestId("history-item")[1];
    expect(item).toHaveTextContent("Structure unavailable");
    expect(item).toHaveTextContent("CodeDNA insufficient data");
    expect(item.textContent).not.toMatch(/Structure 0/);
  });

  it("shows competency scores, categorical levels and level transitions", async () => {
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    const rows = await screen.findAllByTestId("history-competency-row");
    const fd = rows.find((r) => r.getAttribute("data-key") === "FUNCTION_DESIGN");
    expect(fd).toHaveTextContent(/53\.00\s*Developing.*64\.00\s*Developing.*80\.00\s*Established/);
    const ts = rows.find((r) => r.getAttribute("data-key") === "TYPE_STRUCTURE");
    expect(ts).toHaveTextContent("Insufficient evidence");
    expect(ts?.textContent).not.toMatch(/\d+\.\d{2}/);
    expect(screen.getByTestId("history-level-transitions")).toHaveTextContent("Function design: Developing → Established");
  });

  it("keeps a resolved gap visible: GAP, then GAP, then no gap", async () => {
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-skill-gaps");
    const row = screen.getAllByTestId("history-gap-row").find((r) => r.getAttribute("data-key") === "FUNCTION_DESIGN");
    const cells = within(row as HTMLElement).getAllByTestId("history-gap-cell");
    expect(cells.map((c) => c.getAttribute("data-status"))).toEqual(["GAP", "GAP", "NO_GAP"]);
    expect(cells[0]).toHaveTextContent(/Material gap\s*Gap 22\.00\s*High priority/);
    expect(cells[1]).toHaveTextContent(/Gap 11\.00\s*Medium priority/);
    expect(cells[2]).toHaveTextContent("No material gap");
    expect(within(cells[2]).getByTestId("history-gap-resolved")).toBeInTheDocument();
    expect(cells[2].textContent).not.toMatch(/Gap 0/);
  });

  it("marks a missing competency layer unavailable without hiding the assessment", async () => {
    respondWith({ points: [point({ n: 2, analyzedAt: "2026-10-02T10:00:00Z", noCompetency: true, growth: null }), point({ n: 1, analyzedAt: "2026-10-01T10:00:00Z" })] });
    render(<HistoryView projectId={project.id} />);

    const items = await screen.findAllByTestId("history-item");
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent("competency matrix unavailable");
    expect(items[0]).toHaveTextContent("No growth record (assessment incomplete)");
    // Two competency rows and two gap rows, one unavailable cell each.
    expect(screen.getAllByTestId("history-unavailable")).toHaveLength(4);
  });

  it("shows uploaded and GitHub provenance without secrets", async () => {
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    const items = await screen.findAllByTestId("history-item");
    const github = within(items[0]).getByTestId("history-source");
    expect(github).toHaveAttribute("data-origin", "GITHUB");
    expect(github).toHaveTextContent("Source: GitHub repository · octo-org/example-repo @ main · commit 0123456");
    expect(within(github).getByTitle(COMMIT)).toBeInTheDocument();
    expect(within(items[2]).getByTestId("history-source")).toHaveTextContent("Source: Uploaded archive · snapshot v1");
    expect(document.body.textContent).not.toMatch(/token|installation|storage/i);
  });

  it("links each assessment to its stored growth and summarizes it", async () => {
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    const items = await screen.findAllByTestId("history-item");
    expect(within(items[0]).getByTestId("history-growth")).toHaveTextContent("Since the previous assessment: 1 improved · 0 regressed");
    expect(within(items[0]).getByRole("link", { name: "View growth" })).toHaveAttribute("href", `/app/projects/${project.id}/growth?snapshot=01k6t0a1b2c3d4e5f6g7h8jw03`);
    expect(within(items[2]).getByTestId("history-growth")).toHaveTextContent("Baseline established");
  });

  it("compares two selected assessments with server deltas and context-only activity", async () => {
    const user = userEvent.setup();
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    const button = await screen.findByTestId("history-compare");
    expect(button).toBeDisabled();
    const boxes = screen.getAllByRole("checkbox");
    await user.click(boxes[0]);
    expect(button).toBeDisabled();
    await user.click(boxes[2]);
    expect(button).toBeEnabled();
    await user.click(button);

    const panel = await screen.findByTestId("history-comparison");
    expect(panel).toHaveAttribute("data-status", "COMPARED");
    const complexity = within(panel).getAllByTestId("history-observation").find((r) => r.getAttribute("data-metric") === "DNA:COMPLEXITY");
    expect(within(complexity as HTMLElement).getByTestId("history-delta")).toHaveTextContent("+18.00");
    const gap = within(panel).getAllByTestId("history-observation").find((r) => r.getAttribute("data-metric") === "SKILL_GAP:FUNCTION_DESIGN");
    expect(gap).toHaveTextContent("−22.00");
    expect(within(panel).getByTestId("history-basis")).toHaveTextContent("Nothing was saved");
    expect(within(panel).getByTestId("history-activity")).toHaveTextContent("4 learning steps completed and 1 challenge passed");
    expect(within(panel).getByTestId("history-activity")).toHaveTextContent("not evidence");
    expect(urls().find((u) => u.includes("/compare"))).toBe(`/api/v1/projects/${project.id}/history/compare?from=${id(3)}&to=${id(1)}`);
  });

  it("selects at most two assessments, dropping the oldest selection", async () => {
    const user = userEvent.setup();
    respondWith({ points: threeCompatible() });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-timeline");
    const boxes = screen.getAllByRole("checkbox");
    await user.click(boxes[0]);
    await user.click(boxes[1]);
    await user.click(boxes[2]);
    expect(boxes.map((b) => (b as HTMLInputElement).checked)).toEqual([false, true, true]);
  });

  it("shows an incomparable comparison with the differing versions and no deltas", async () => {
    const user = userEvent.setup();
    respondWith({
      points: threeCompatible(),
      compare: comparison({
        status: "INCOMPARABLE",
        differences: ["dna_scoring_version"],
        layers: { dna: "INCOMPARABLE", competency: "INCOMPARABLE", skill_gaps: "INCOMPARABLE" },
        dna: [],
        competencies: [],
        skill_gaps: [],
        from: { ...threeCompatible()[2], versions: { dna_scoring_version: "1.0.0" } },
        to: { ...threeCompatible()[0], versions: { dna_scoring_version: "2.0.0" } },
      }),
    });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-timeline");
    const boxes = screen.getAllByRole("checkbox");
    await user.click(boxes[0]);
    await user.click(boxes[2]);
    await user.click(screen.getByTestId("history-compare"));

    const panel = await screen.findByTestId("history-comparison");
    expect(panel).toHaveAttribute("data-status", "INCOMPARABLE");
    expect(within(panel).getByTestId("history-incomparable")).toHaveTextContent("DNA scoring version: 1.0.0 → 2.0.0");
    expect(within(panel).queryAllByTestId("history-delta")).toHaveLength(0);
    expect(panel.textContent).toContain("not a regression");
  });

  it("shows an unavailable layer in a comparison instead of values", async () => {
    const user = userEvent.setup();
    respondWith({ points: threeCompatible(), compare: comparison({ layers: { dna: "COMPARED", competency: "UNAVAILABLE", skill_gaps: "UNAVAILABLE" }, competencies: [], skill_gaps: [] }) });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-timeline");
    const boxes = screen.getAllByRole("checkbox");
    await user.click(boxes[0]);
    await user.click(boxes[1]);
    await user.click(screen.getByTestId("history-compare"));

    expect(await screen.findAllByTestId("history-layer-unavailable")).toHaveLength(2);
    expect(screen.getAllByTestId("history-comparison-layer").map((l) => l.getAttribute("data-type"))).toEqual(["DNA"]);
  });

  it("shows a comparison error without losing the history", async () => {
    const user = userEvent.setup();
    respondWith({ points: threeCompatible(), compare: apiErrorResponse(404, "RESOURCE_NOT_FOUND") });
    render(<HistoryView projectId={project.id} />);

    await screen.findByTestId("history-timeline");
    const boxes = screen.getAllByRole("checkbox");
    await user.click(boxes[0]);
    await user.click(boxes[1]);
    await user.click(screen.getByTestId("history-compare"));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.getByTestId("history-timeline")).toBeInTheDocument();
  });

  it("pages through older assessments", async () => {
    const user = userEvent.setup();
    respondWith({ points: threeCompatible(), meta: { total: 30, last_page: 2 } });
    render(<HistoryView projectId={project.id} />);

    const nav = await screen.findByTestId("history-pagination");
    expect(within(nav).getByRole("button", { name: "Newer assessments" })).toBeDisabled();
    await user.click(within(nav).getByRole("button", { name: "Older assessments" }));
    await screen.findByTestId("history-timeline");
    expect(urls()).toContain(`/api/v1/projects/${project.id}/history?page=2&per_page=25`);
  });

  it("keeps an archived project's history readable", async () => {
    respondWith({ points: threeCompatible(), projectOverride: { status: "ARCHIVED" } });
    render(<HistoryView projectId={project.id} />);

    expect(await screen.findByTestId("history-archived")).toBeInTheDocument();
    expect(screen.getAllByTestId("history-item")).toHaveLength(3);
  });

  it("shows another user's project as not found", async () => {
    respondWith({ points: [], history: apiErrorResponse(404, "RESOURCE_NOT_FOUND") });
    fetchMock.mockImplementation(async () => apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    render(<HistoryView projectId={project.id} />);

    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(document.body.textContent).toContain("belongs to another account");
  });

  it("rejects an invalid project ID without a request", () => {
    render(<HistoryView projectId="../../etc" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("sends a signed-out user to the login page", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<HistoryView projectId={project.id} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("shows an error with a retry", async () => {
    const user = userEvent.setup();
    respondWith({ points: [], history: apiErrorResponse(500, "INTERNAL_ERROR") });
    render(<HistoryView projectId={project.id} />);

    const retry = await screen.findByRole("button", { name: "Try again" });
    respondWith({ points: threeCompatible() });
    await user.click(retry);
    expect(await screen.findByTestId("history-timeline")).toBeInTheDocument();
  });
});
