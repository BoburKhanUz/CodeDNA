import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { RoadmapView } from "@/components/roadmap/roadmap-view";
import type { Project, Roadmap, SkillGapSnapshotSummary } from "@/lib/api/types";
import { completeStep } from "@/lib/roadmap/client";
import { formatMinutes, rankExplanation } from "@/lib/roadmap/format";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { afterFirstStep, OLD_ROADMAP_ID, ROADMAP_ID, roadmap, step, summaryOf } from "@/test/roadmap";
import { aiReady } from "@/test/insights";
import { resetRouter, router } from "@/test/router";
import { gapsSnapshot, insufficientSnapshot, noGapsSnapshot, skillGapSummary } from "@/test/skill-gap";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
const NOTICE = "Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.";

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

interface Scenario {
  roadmaps?: Roadmap[];
  gaps?: SkillGapSnapshotSummary[];
  projectOverride?: Partial<Project>;
  post?: (url: string) => Response;
}

/** GET /roadmaps lists the given roadmaps; GET /roadmaps/{id} returns the matching one. */
function respondWith({ roadmaps = [], gaps = [skillGapSummary(gapsSnapshot)], projectOverride = {}, post }: Scenario) {
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    if (url.endsWith("/ai/status")) return jsonResponse({ data: aiReady });
    if (url.includes("/insights")) return jsonResponse({ data: [] });
    if (init?.method === "POST") return post ? post(url) : jsonResponse({ data: roadmap() }, 201);
    const detail = /\/roadmaps\/([0-9a-z]{26})$/.exec(url);
    if (detail) {
      const found = roadmaps.find((r) => r.id === detail[1]);
      return found ? jsonResponse({ data: found }) : apiErrorResponse(404, "RESOURCE_NOT_FOUND");
    }
    if (url.includes("/roadmaps")) return jsonResponse(page(roadmaps.map(summaryOf)));
    if (url.includes("/skill-gaps")) return jsonResponse(page(gaps));
    return jsonResponse({ data: { ...project, ...projectOverride } });
  });
}

function posts(): string[] {
  return fetchMock.mock.calls.filter(([, init]) => init?.method === "POST").map(([input, init]) => `${String(input)} ${String(init?.body)}`);
}

describe("RoadmapView", () => {
  it("shows a loading state, then the roadmap with the notice", async () => {
    respondWith({ roadmaps: [roadmap()] });
    render(<RoadmapView projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading learning roadmap" })).toBeInTheDocument();
    expect(await screen.findByTestId("roadmap-notice")).toHaveTextContent(NOTICE);
    expect(screen.getByRole("heading", { level: 1, name: "Learning Roadmap" })).toBeInTheDocument();
    expect(within(screen.getByRole("navigation", { name: "Related pages" })).getByRole("link", { name: "Growth" })).toHaveAttribute("href", `/app/projects/${project.id}/growth`);
  });

  it("offers AI guidance for an active roadmap, read-only when the project is archived", async () => {
    respondWith({ roadmaps: [roadmap()] });
    const { unmount } = render(<RoadmapView projectId={project.id} />);
    const panel = await screen.findByTestId("insight-panel");
    expect(panel).toHaveAttribute("data-kind", "ROADMAP_GUIDANCE");
    expect(within(panel).getByTestId("insight-request")).toBeEnabled();
    unmount();

    respondWith({ roadmaps: [roadmap()], projectOverride: { status: "ARCHIVED" } });
    render(<RoadmapView projectId={project.id} />);
    expect(await screen.findByTestId("insight-panel")).toBeInTheDocument();
    expect(screen.queryByTestId("insight-request")).not.toBeInTheDocument();
  });

  it("shows the development focus: why, current, target, gap, priority and evidence quality", async () => {
    respondWith({ roadmaps: [roadmap()] });
    render(<RoadmapView projectId={project.id} />);

    const first = await screen.findByTestId("focus-CODE_HYGIENE");
    expect(first).toHaveTextContent("1. Code hygiene");
    expect(first).toHaveTextContent("High priority");
    expect(first).toHaveTextContent("Current 60.00 (Developing) · target 90.00 · gap 30.00 points · evidence quality 90.00%");
    expect(first).toHaveTextContent("Ranked above Function design because of a higher gap priority.");
    const second = screen.getByTestId("focus-FUNCTION_DESIGN");
    expect(second).toHaveTextContent("priority capped at medium: limited evidence");
    expect(second).toHaveTextContent("It is the last actionable gap in order.");
    expect(screen.getByTestId("excluded-COMPLEXITY_MANAGEMENT")).toHaveTextContent("Not enough evidence: no learning need can be established.");
    expect(screen.getByTestId("excluded-TYPE_STRUCTURE")).toHaveTextContent("No material gap: nothing to work on.");
  });

  it("lists the tracks in focus order with ordered steps, estimates and learning progress", async () => {
    respondWith({ roadmaps: [roadmap()] });
    render(<RoadmapView projectId={project.id} />);

    const tracks = await screen.findAllByTestId(/^track-/);
    expect(tracks.map((t) => t.getAttribute("data-testid"))).toEqual(["track-CODE_HYGIENE", "track-FUNCTION_DESIGN"]);
    const steps = within(tracks[0]).getAllByTestId(/^step-ch-/);
    expect(steps.map((s) => s.getAttribute("data-testid"))).toEqual(["step-ch-syntax", "step-ch-fix", "step-ch-challenge", "step-ch-reassess"]);
    expect(steps[0]).toHaveTextContent("Understand");
    expect(tracks[0]).toHaveTextContent("Code hygiene · 0 of 4 steps done · about 1 h 50 min");
    expect(screen.getByTestId("roadmap-progress")).toHaveTextContent("0 of 6 steps done · Active · about 3 h 5 min in total");
    expect(screen.getByTestId("roadmap-progress")).toHaveTextContent("This is not a CodeDNA assessment and not evidence of skill.");
  });

  it("completes a step and updates the progress, sending nothing but the step", async () => {
    respondWith({ roadmaps: [roadmap()], post: () => jsonResponse({ data: afterFirstStep() }) });
    const ui = userEvent.setup();
    render(<RoadmapView projectId={project.id} />);

    const first = await screen.findByTestId("step-ch-syntax");
    expect(within(screen.getByTestId("step-ch-fix")).getByTestId("step-state")).toHaveTextContent("Complete the earlier steps it depends on first.");
    await ui.click(within(first).getByRole("button", { name: "Mark as done" }));

    expect(posts()).toEqual([`/api/v1/projects/${project.id}/roadmaps/${ROADMAP_ID}/steps/ch-syntax/complete {}`]);
    expect(await within(screen.getByTestId("step-ch-syntax")).findByText(/✓ Done/)).toBeInTheDocument();
    expect(within(screen.getByTestId("step-ch-fix")).getByRole("button", { name: "Mark as done" })).toBeInTheDocument();
    expect(screen.getByTestId("roadmap-progress")).toHaveTextContent("1 of 6 steps done");
  });

  it("shows completion refusals from the API", async () => {
    respondWith({ roadmaps: [roadmap()], post: () => apiErrorResponse(409, "ROADMAP_STEP_PREREQUISITES_INCOMPLETE") });
    const ui = userEvent.setup();
    render(<RoadmapView projectId={project.id} />);

    await ui.click(within(await screen.findByTestId("step-ch-syntax")).getByRole("button", { name: "Mark as done" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Complete the earlier steps this step depends on first.");
  });

  it("links challenge steps to the challenge practice, and reassessment to a new analysis", async () => {
    const practised = roadmap();
    practised.tracks[0].steps[2] = { ...practised.tracks[0].steps[2], practice: { challenge_id: "01k6p0a1b2c3d4e5f6g7h8j9ch", definition_key: "CODE_HYGIENE_001", status: "PASSED" } };
    respondWith({ roadmaps: [practised] });
    render(<RoadmapView projectId={project.id} />);

    const challenge = within(await screen.findByTestId("step-ch-challenge")).getByTestId("step-challenge");
    expect(challenge).toHaveTextContent("Recommended challenge: Repair the configuration parser (Beginner exercise)");
    expect(within(challenge).getByRole("link", { name: "Open your challenge (Passed)" })).toHaveAttribute(
      "href",
      `/app/projects/${project.id}/challenges/01k6p0a1b2c3d4e5f6g7h8j9ch`,
    );
    expect(challenge).toHaveTextContent("Passing a challenge is practice: it does not close the skill gap.");
    const reassess = screen.getByTestId("step-ch-reassess");
    expect(within(reassess).getByRole("link", { name: /run a new analysis/ })).toHaveAttribute("href", `/app/projects/${project.id}`);
    expect(reassess).toHaveTextContent("nothing is started automatically");
  });

  it("links to Coding Challenges before a challenge is assigned, and explains a missing challenge", async () => {
    const r = roadmap();
    r.tracks[1].steps = [step({ key: "fd-challenge", position: 5, type: "CHALLENGE", title: "Function challenge" }), ...r.tracks[1].steps];
    respondWith({ roadmaps: [r] });
    render(<RoadmapView projectId={project.id} />);

    expect(within(await screen.findByTestId("step-ch-challenge")).getByRole("link", { name: "Open Coding Challenges" })).toHaveAttribute(
      "href",
      `/app/projects/${project.id}/challenges`,
    );
    expect(screen.getByTestId("step-fd-challenge")).toHaveTextContent("No matching challenge is available for this competency.");
  });

  describe("without a roadmap", () => {
    it("offers to create one from an analysis with gaps, sending an empty request", async () => {
      let created = false;
      fetchMock.mockImplementation(async (input, init) => {
        const url = String(input);
        if (init?.method === "POST") {
          created = true;
          return jsonResponse({ data: roadmap() }, 201);
        }
        if (url.endsWith(`/roadmaps/${ROADMAP_ID}`)) return jsonResponse({ data: roadmap() });
        if (url.includes("/roadmaps")) return jsonResponse(page(created ? [summaryOf(roadmap())] : []));
        if (url.includes("/skill-gaps")) return jsonResponse(page([skillGapSummary(gapsSnapshot)]));
        return jsonResponse({ data: project });
      });
      const ui = userEvent.setup();
      render(<RoadmapView projectId={project.id} />);

      expect(await screen.findByTestId("roadmap-empty")).toHaveTextContent("No roadmap yet");
      await ui.click(screen.getByRole("button", { name: "Create learning roadmap" }));

      expect(posts()).toEqual([`/api/v1/projects/${project.id}/roadmaps {}`]);
      expect(await screen.findByTestId("development-focus")).toBeInTheDocument();
    });

    it("stays busy until the new roadmap is shown, so one click sends one request", async () => {
      let created = false;
      fetchMock.mockImplementation(async (input, init) => {
        const url = String(input);
        if (init?.method === "POST") {
          created = true;
          return jsonResponse({ data: roadmap() }, 201);
        }
        if (created) return new Promise<Response>(() => {});
        if (url.includes("/roadmaps")) return jsonResponse(page([]));
        if (url.includes("/skill-gaps")) return jsonResponse(page([skillGapSummary(gapsSnapshot)]));
        return jsonResponse({ data: project });
      });
      const ui = userEvent.setup();
      render(<RoadmapView projectId={project.id} />);
      const button = await screen.findByRole("button", { name: "Create learning roadmap" });
      await ui.click(button);
      await ui.click(button);

      expect(posts()).toHaveLength(1);
      expect(button).toBeDisabled();
    });

    it.each([
      ["no analysis", [], "No roadmap available"],
      ["not enough evidence", [skillGapSummary(insufficientSnapshot)], "Not enough evidence"],
      ["no material gaps", [skillGapSummary(noGapsSnapshot)], "No active development focus"],
    ])("explains %s and offers nothing to create", async (_, gaps, title) => {
      respondWith({ gaps });
      render(<RoadmapView projectId={project.id} />);

      expect(await screen.findByTestId("roadmap-empty")).toHaveTextContent(title);
      expect(screen.queryByRole("button", { name: "Create learning roadmap" })).not.toBeInTheDocument();
      expect(screen.getByTestId("roadmap-notice")).toHaveTextContent(NOTICE);
      expect(screen.getByTestId("roadmap-empty")).not.toHaveTextContent(/\b0\.00\b/);
    });
  });

  it("offers an update when a newer analysis has gaps, and only explains it when it has none", async () => {
    const newer = { ...skillGapSummary(gapsSnapshot), id: "01k6p0a1b2c3d4e5f6g7h8j9nw" };
    respondWith({ roadmaps: [roadmap()], gaps: [newer] });
    const ui = userEvent.setup();
    const { unmount } = render(<RoadmapView projectId={project.id} />);

    expect(await screen.findByTestId("roadmap-newer-analysis")).toHaveTextContent("A newer skill gap analysis is available.");
    await ui.click(screen.getByRole("button", { name: "Update roadmap" }));
    expect(posts()).toEqual([`/api/v1/projects/${project.id}/roadmaps {}`]);
    unmount();

    respondWith({ roadmaps: [roadmap()], gaps: [{ ...skillGapSummary(noGapsSnapshot), id: "01k6p0a1b2c3d4e5f6g7h8j9nw" }] });
    render(<RoadmapView projectId={project.id} />);
    expect(await screen.findByTestId("roadmap-newer-analysis")).toHaveTextContent("has no actionable gap (no material gaps), so this roadmap stays as it is.");
    expect(screen.queryByRole("button", { name: "Update roadmap" })).not.toBeInTheDocument();
  });

  it("keeps an archived project's roadmap read-only", async () => {
    respondWith({ roadmaps: [roadmap()], projectOverride: { status: "ARCHIVED" } });
    render(<RoadmapView projectId={project.id} />);

    expect(await screen.findByTestId("roadmap-archived")).toHaveTextContent("progress can no longer change");
    expect(screen.queryByRole("button", { name: "Mark as done" })).not.toBeInTheDocument();
  });

  it("shows a superseded roadmap from the history read-only, with its kept progress", async () => {
    const current = roadmap();
    const old = { ...afterFirstStep(), id: OLD_ROADMAP_ID, status: "SUPERSEDED" as const, superseded_at: "2026-10-15T08:00:00Z", superseded_by: ROADMAP_ID, created_at: "2026-10-13T08:00:00Z" };
    respondWith({ roadmaps: [current, old] });
    render(<RoadmapView projectId={project.id} roadmapId={OLD_ROADMAP_ID} />);

    expect(await screen.findByTestId("roadmap-superseded")).toHaveTextContent("a roadmap for a newer skill gap analysis replaced this one");
    expect(within(screen.getByTestId("roadmap-superseded")).getByRole("link", { name: "Open the current roadmap" })).toHaveAttribute(
      "href",
      `/app/projects/${project.id}/roadmap`,
    );
    expect(screen.queryByRole("button", { name: "Mark as done" })).not.toBeInTheDocument();
    expect(screen.getByTestId("roadmap-progress")).toHaveTextContent("1 of 6 steps done · Superseded");
    expect(screen.getByTestId("roadmap-history")).toHaveTextContent("Superseded · 1 of 6 steps");
  });

  it("shows the roadmap last navigated to, even when an earlier one answers later", async () => {
    const current = roadmap();
    const old = { ...afterFirstStep(), id: OLD_ROADMAP_ID, status: "SUPERSEDED" as const, superseded_at: "2026-10-15T08:00:00Z", superseded_by: ROADMAP_ID, created_at: "2026-10-13T08:00:00Z" };
    respondWith({ roadmaps: [current, old] });
    const respond = fetchMock.getMockImplementation()!;
    let finishOld: () => void = () => {};
    fetchMock.mockImplementation((input, init) =>
      String(input).endsWith(`/roadmaps/${OLD_ROADMAP_ID}`)
        ? new Promise<Response>((resolve) => (finishOld = () => resolve(jsonResponse({ data: old }))))
        : respond(input, init),
    );
    const { rerender } = render(<RoadmapView projectId={project.id} roadmapId={OLD_ROADMAP_ID} />);
    await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).endsWith(`/roadmaps/${OLD_ROADMAP_ID}`))).toBe(true));

    rerender(<RoadmapView projectId={project.id} roadmapId={ROADMAP_ID} />);
    expect(await screen.findByTestId("roadmap-progress")).not.toHaveTextContent("Superseded");
    await act(async () => finishOld());

    expect(screen.queryByTestId("roadmap-superseded")).not.toBeInTheDocument();
    expect(screen.getByTestId("roadmap-progress")).not.toHaveTextContent("Superseded");
  });

  it("notes a roadmap generated with an earlier catalog or rules version", async () => {
    respondWith({ roadmaps: [roadmap({ current: { catalog: false, rules: true } })] });
    render(<RoadmapView projectId={project.id} />);

    expect(await screen.findByTestId("roadmap-version-note")).toHaveTextContent("earlier roadmap catalog or rules version");
  });

  it("shows not found for a 404 or an invalid ID, and signs out on 401", async () => {
    respondWith({ roadmaps: [roadmap()] });
    const { unmount } = render(<RoadmapView projectId={project.id} roadmapId={OLD_ROADMAP_ID} />);
    expect(await screen.findByRole("heading", { name: "Roadmap not found" })).toBeInTheDocument();
    unmount();

    render(<RoadmapView projectId="../etc" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();

    fetchMock.mockImplementation(async () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<RoadmapView projectId={project.id} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("shows an error with retry", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(500, "INTERNAL_ERROR"));
    const ui = userEvent.setup();
    render(<RoadmapView projectId={project.id} />);

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    respondWith({ roadmaps: [roadmap()] });
    await ui.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByTestId("development-focus")).toBeInTheDocument();
  });

  it("offers no AI, chat or arbitrary content outside the labeled AI panel", async () => {
    respondWith({ roadmaps: [roadmap()] });
    render(<RoadmapView projectId={project.id} />);

    await screen.findByTestId("development-focus");
    // Since Phase 29 the only AI content is the optional, labeled insight
    // panel; wait for it, so the check below never races its loading.
    expect(await screen.findByTestId("insight-ai-label")).toHaveTextContent("AI-generated");
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    const deterministic = document.body.cloneNode(true) as HTMLElement;
    deterministic.querySelectorAll('[data-testid="insight-panel"]').forEach((panel) => panel.remove());
    expect(deterministic.textContent).not.toMatch(/\bAI\b|chat|hint/i);
  });
});

describe("roadmap client", () => {
  it("refuses malformed step keys and IDs before calling the API", async () => {
    await expect(completeStep(project.id, ROADMAP_ID, "../../etc")).rejects.toThrow("Invalid step.");
    await expect(completeStep(project.id, "../x", "ch-syntax")).rejects.toThrow("Invalid roadmap ID.");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("roadmap format", () => {
  it("formats durations and explains the rank", () => {
    expect([formatMinutes(45), formatMinutes(60), formatMinutes(185)]).toEqual(["45 min", "1 h", "3 h 5 min"]);
    expect(rankExplanation(1, null, null)).toBe("It is the only actionable gap.");
    expect(rankExplanation(2, "RAW_GAP", "CODE_HYGIENE")).toBe("Ranked above Code hygiene because of a larger gap.");
    expect(rankExplanation(1, "EVIDENCE_QUALITY", "TYPE_STRUCTURE")).toBe("Ranked above Type structure because of better evidence quality.");
  });
});
