import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SkillGapAnalysis } from "@/components/skill-gap/skill-gap-analysis";
import type { SkillGapSnapshot } from "@/lib/api/types";
import { priorityLabel, skillGapStatusLabel, targetProfileLabel, unmeasuredExplanation } from "@/lib/skill-gap/format";
import { assessedMatrix, competencySummary } from "@/test/competency";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";
import { gapsSnapshot, insufficientSnapshot, noGapsSnapshot, SKILL_GAP_ID, skillGapSummary } from "@/test/skill-gap";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

function respondWith(snapshot: SkillGapSnapshot | null, options: { hasCompetencies?: boolean } = {}) {
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    if (url.includes("/skill-gaps/")) return snapshot ? jsonResponse({ data: snapshot }) : apiErrorResponse(404, "RESOURCE_NOT_FOUND");
    if (url.includes("/skill-gaps")) return jsonResponse(page(snapshot ? [skillGapSummary(snapshot)] : []));
    if (url.includes("/competencies")) return jsonResponse(page(options.hasCompetencies ? [competencySummary(assessedMatrix)] : []));
    return jsonResponse({ data: project });
  });
}

describe("SkillGapAnalysis", () => {
  it("shows a skeleton while loading", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    render(<SkillGapAnalysis projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading skill gaps" })).toBeInTheDocument();
  });

  it("shows the target profile, thresholds and the material-gap summary from the API", async () => {
    respondWith(gapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);

    const target = await screen.findByTestId("skill-gap-target");
    expect(within(target).getByRole("heading", { name: "Target profile: Engineering standard 1.0.0" })).toBeInTheDocument();
    expect(target).toHaveTextContent("Not a job title or seniority level.");
    expect(target).toHaveTextContent("The target values are product calibration choices and are not presented as empirical industry standards.");
    expect(screen.getByTestId("skill-gap-summary")).toHaveTextContent("1 material gap · Low priority: 0 · Medium priority: 0 · High priority: 1");
    expect(target).toHaveTextContent("A gap is material from 5.00 points.");
    expect(target).toHaveTextContent("Low priority from 5.00, Medium priority from 15.00, High priority from 30.00");
    expect(target).toHaveTextContent("evidence quality of at least 60.00%");
    expect(target).toHaveTextContent("Skill gap version 1.0.0 · calculated Oct 12, 2026, 8:00 AM UTC · source snapshot v1");
    expect(fetchMock.mock.calls.map(([input]) => String(input))).toContain(`/api/v1/projects/${project.id}/skill-gaps/${SKILL_GAP_ID}`);
  });

  it("shows current, target, gap, priority and evidence quality per competency as returned", async () => {
    respondWith(gapsSnapshot);
    const ui = userEvent.setup();
    render(<SkillGapAnalysis projectId={project.id} />);

    const hygiene = await screen.findByTestId("skill-gap-CODE_HYGIENE");
    expect(within(hygiene).getByTestId("skill-gap-status")).toHaveTextContent("Material gap");
    expect(within(hygiene).getByTestId("skill-gap-priority")).toHaveTextContent("High priority");
    expect(within(hygiene).getByTestId("skill-gap-current")).toHaveTextContent("60.00");
    expect(within(hygiene).getByTestId("skill-gap-target-score")).toHaveTextContent("90.00");
    expect(within(hygiene).getByTestId("skill-gap-gap")).toHaveTextContent("Gap: 30.00 points");
    expect(hygiene).toHaveTextContent("Evidence quality90.00%");
    expect(hygiene).toHaveTextContent("Competency levelDeveloping");

    await ui.click(within(hygiene).getByText("Evidence (1)"));
    expect(hygiene).toHaveTextContent("Target rationale: Syntax validity is expected of analyzable code");
    const evidence = within(hygiene).getByTestId("skill-gap-evidence-CODE_HYGIENE.syntax_error_share");
    expect(evidence).toHaveTextContent("Files with syntax errors");
    expect(evidence).toHaveTextContent("From CodeDNA CODE_HYGIENE.syntax_error_share · Measured · evidence score 60.00");

    const complexity = screen.getByTestId("skill-gap-COMPLEXITY_MANAGEMENT");
    expect(within(complexity).getByTestId("skill-gap-status")).toHaveTextContent("No material gap");
    expect(within(complexity).queryByTestId("skill-gap-priority")).not.toBeInTheDocument();
    expect(within(complexity).getByTestId("skill-gap-gap")).toHaveTextContent("Gap: 0.00 points (below the material-gap threshold)");
  });

  it("links to the coding challenges and says they do not change the gaps", async () => {
    respondWith(gapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);

    const card = await screen.findByTestId("challenge-link");
    expect(within(card).getByRole("link", { name: "View Coding Challenges →" })).toHaveAttribute("href", `/app/projects/${project.id}/challenges`);
    expect(card).toHaveTextContent("Completing a challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.");
  });

  it("links to the learning roadmap and says it does not change the gaps", async () => {
    respondWith(gapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);

    const card = await screen.findByTestId("roadmap-link");
    expect(within(card).getByRole("link", { name: "View Learning Roadmap →" })).toHaveAttribute("href", `/app/projects/${project.id}/roadmap`);
    expect(card).toHaveTextContent("Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.");
  });

  it("links to the AI assessment", async () => {
    respondWith(gapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);

    const card = await screen.findByTestId("assessment-link");
    expect(within(card).getByRole("link", { name: "View AI Assessment →" })).toHaveAttribute("href", `/app/projects/${project.id}/assessment`);
    expect(card).toHaveTextContent("It does not change any score, gap or priority.");
  });

  it("states that there are no material gaps, which is not the same as no data", async () => {
    respondWith(noGapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);

    expect(await screen.findByTestId("skill-gap-none")).toHaveTextContent(
      "No material competency gaps were identified against the selected engineering standard.",
    );
    expect(screen.getByTestId("skill-gap-summary")).toHaveTextContent("0 material gaps");
    // The immaterial raw gap is still shown.
    expect(within(screen.getByTestId("skill-gap-CODE_HYGIENE")).getByTestId("skill-gap-gap")).toHaveTextContent("Gap: 4.00 points (below the material-gap threshold)");
    const types = screen.getByTestId("skill-gap-TYPE_STRUCTURE");
    expect(within(types).getByTestId("skill-gap-unmeasured")).toHaveTextContent("Insufficient evidence to determine this competency gap.");
    expect(screen.queryByText(/no weaknesses/i)).not.toBeInTheDocument();
  });

  it("shows insufficient and unsupported evidence without a gap, never 0", async () => {
    respondWith(insufficientSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);

    expect(await screen.findByTestId("skill-gap-insufficient")).toHaveTextContent("This is not the same as having no gaps.");
    const complexity = screen.getByTestId("skill-gap-COMPLEXITY_MANAGEMENT");
    expect(within(complexity).getByTestId("skill-gap-status")).toHaveTextContent("Insufficient evidence");
    expect(within(complexity).getByTestId("skill-gap-unmeasured")).toHaveTextContent("Insufficient evidence to determine this competency gap.");
    expect(complexity).toHaveTextContent("Target: 75.00 (no gap is computed without evidence)");
    expect(within(complexity).queryByTestId("skill-gap-gap")).not.toBeInTheDocument();
    expect(within(complexity).queryByTestId("skill-gap-priority")).not.toBeInTheDocument();
    expect(complexity).not.toHaveTextContent("points");

    const hygiene = screen.getByTestId("skill-gap-CODE_HYGIENE");
    expect(within(hygiene).getByTestId("skill-gap-status")).toHaveTextContent("Unsupported evidence");
    expect(hygiene).toHaveTextContent("cannot measure the evidence for this competency in the analyzed languages");
  });

  it("explains a capped priority and renders backend values without recomputing them", async () => {
    const odd: SkillGapSnapshot = {
      ...gapsSnapshot,
      results: gapsSnapshot.results.map((r) =>
        r.competency_key === "CODE_HYGIENE" ? { ...r, raw_gap: "0.1111", priority: "MEDIUM", priority_capped: true } : r,
      ),
    };
    respondWith(odd);
    render(<SkillGapAnalysis projectId={project.id} />);

    const hygiene = await screen.findByTestId("skill-gap-CODE_HYGIENE");
    // 90.00 − 60.00 would be 30.00: the page shows the backend's value instead.
    expect(within(hygiene).getByTestId("skill-gap-gap")).toHaveTextContent("Gap: 11.11 points");
    expect(within(hygiene).getByTestId("skill-gap-priority")).toHaveTextContent("Medium priority");
    expect(hygiene).toHaveTextContent("Capped at medium priority: the evidence quality is below the bound for high priority.");
  });

  it("links to the competency matrix, CodeDNA and the project", async () => {
    respondWith(gapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);
    await screen.findByTestId("skill-gap-CODE_HYGIENE");

    const nav = screen.getByRole("navigation", { name: "Related pages" });
    expect(within(nav).getByRole("link", { name: `← ${project.name}` })).toHaveAttribute("href", `/app/projects/${project.id}`);
    expect(within(nav).getByRole("link", { name: "CodeDNA" })).toHaveAttribute("href", `/app/projects/${project.id}/dna`);
    expect(within(nav).getByRole("link", { name: "Competency Matrix" })).toHaveAttribute("href", `/app/projects/${project.id}/competencies`);
    expect(screen.getByRole("link", { name: /Competency version 1\.0\.0/ })).toHaveAttribute("href", `/app/projects/${project.id}/competencies`);
    expect(screen.getByRole("link", { name: "CodeDNA scoring 1.0.0" })).toHaveAttribute("href", `/app/projects/${project.id}/dna/${gapsSnapshot.dna_snapshot_id}`);
  });

  it("explains when no analysis exists, depending on whether a competency matrix exists", async () => {
    respondWith(null);
    const { unmount } = render(<SkillGapAnalysis projectId={project.id} />);
    expect(await screen.findByTestId("skill-gap-empty")).toHaveTextContent("needs a completed static analysis");
    unmount();

    respondWith(null, { hasCompetencies: true });
    render(<SkillGapAnalysis projectId={project.id} />);
    expect(await screen.findByTestId("skill-gap-empty")).toHaveTextContent("A competency matrix exists, but no skill gap analysis has been derived from it yet.");
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("treats missing and foreign projects alike and rejects invalid IDs without a request", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const { unmount } = render(<SkillGapAnalysis projectId={project.id} />);
    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    unmount();

    fetchMock.mockReset();
    render(<SkillGapAnalysis projectId="../admin" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows API errors with their reference and retries", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(500, "INTERNAL_ERROR", { requestId: "req-999" }));
    const ui = userEvent.setup();
    render(<SkillGapAnalysis projectId={project.id} />);

    expect(await screen.findByRole("alert")).toHaveTextContent("Reference: req-999");
    respondWith(gapsSnapshot);
    await ui.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByTestId("skill-gap-CODE_HYGIENE")).toHaveTextContent("30.00 points");
  });

  it("sends signed-out users to the login page", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<SkillGapAnalysis projectId={project.id} />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("only reads, sends no targets and uses no judgments about people", async () => {
    respondWith(gapsSnapshot);
    render(<SkillGapAnalysis projectId={project.id} />);
    await screen.findByTestId("skill-gap-CODE_HYGIENE");

    expect(fetchMock.mock.calls.every(([input, init]) => (init?.method ?? "GET") === "GET" && !String(input).includes("target"))).toBe(true);
    const disclaimer = screen.getByText(/do not establish developer seniority/);
    const rest = (document.body.textContent ?? "").replace(disclaimer.textContent ?? "", "").replace("Not a job title or seniority level.", "");
    expect(rest).not.toMatch(/junior|middle|senior|expert|weak|you are|developer is|course|learn |practice/i);
  });
});

describe("skill gap labels", () => {
  it("labels statuses, priorities and profiles neutrally", () => {
    expect(skillGapStatusLabel("GAP")).toBe("Material gap");
    expect(skillGapStatusLabel("NOT_TARGETED")).toBe("Not targeted");
    expect(priorityLabel("HIGH")).toBe("High priority");
    expect(priorityLabel("URGENT")).toBeNull();
    expect(targetProfileLabel("ENGINEERING_STANDARD")).toBe("Engineering standard");
    expect(unmeasuredExplanation("GAP")).toBeNull();
    expect(unmeasuredExplanation("MISSING")).toBe("The competency evidence is not available, so no gap is determined.");
  });
});
