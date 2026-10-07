import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CompetencyMatrix } from "@/components/competency/competency-matrix";
import type { CompetencySnapshot } from "@/lib/api/types";
import { levelBadge, levelLabel, levelMeaning } from "@/lib/competency/format";
import { assessedMatrix, COMPETENCY_ID, competencySummary, insufficientMatrix } from "@/test/competency";
import { readySnapshot, summary } from "@/test/dna";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
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
});

function respondWith(snapshot: CompetencySnapshot | null, options: { hasDna?: boolean } = {}) {
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    if (url.includes("/competencies/")) return snapshot ? jsonResponse({ data: snapshot }) : apiErrorResponse(404, "RESOURCE_NOT_FOUND");
    if (url.includes("/competencies")) return jsonResponse(page(snapshot ? [competencySummary(snapshot)] : []));
    if (url.includes("/dna")) return jsonResponse(page(options.hasDna ? [summary(readySnapshot)] : []));
    return jsonResponse({ data: project });
  });
}

describe("CompetencyMatrix", () => {
  it("shows a skeleton while loading", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    render(<CompetencyMatrix projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading Competency Matrix" })).toBeInTheDocument();
  });

  it("renders every competency with its score, level and evidence quality as returned", async () => {
    respondWith(assessedMatrix);
    render(<CompetencyMatrix projectId={project.id} />);

    expect(await screen.findByRole("heading", { level: 1, name: "Competency Matrix" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Growth" })).toHaveAttribute("href", `/app/projects/${project.id}/growth`);
    expect(screen.getByText(/Competency version 1\.0\.0 · calculated Oct 12, 2026, 8:00 AM UTC · source snapshot v1/)).toBeInTheDocument();
    expect(screen.getByTestId("competency-summary")).toHaveTextContent("4 of 4 competencies assessed");

    const complexity = screen.getByTestId("competency-COMPLEXITY_MANAGEMENT");
    expect(within(complexity).getByRole("heading", { name: "Complexity management" })).toBeInTheDocument();
    expect(complexity).toHaveTextContent("75.00/ 100");
    expect(within(complexity).getByTestId("competency-level")).toHaveTextContent("Level 2 · Established");
    expect(complexity).toHaveTextContent("Available source-code evidence meets the defined threshold for this competency.");
    expect(complexity).toHaveTextContent("Evidence quality90.00%");
    expect(complexity).toHaveTextContent("Evidence available2 of 2");
    expect(within(screen.getByTestId("competency-FUNCTION_DESIGN")).getByTestId("competency-level")).toHaveTextContent("Level 3 · Strong");
    expect(within(screen.getByTestId("competency-CODE_HYGIENE")).getByTestId("competency-level")).toHaveTextContent("Level 1 · Developing");
    expect(fetchMock.mock.calls.map(([input]) => String(input))).toContain(`/api/v1/projects/${project.id}/competencies/${COMPETENCY_ID}`);
  });

  it("explains each result with its exact evidence, thresholds and level boundaries", async () => {
    respondWith(assessedMatrix);
    const ui = userEvent.setup();
    render(<CompetencyMatrix projectId={project.id} />);

    const complexity = await screen.findByTestId("competency-COMPLEXITY_MANAGEMENT");
    await ui.click(within(complexity).getByText("Why this result (2 evidence)"));
    const mean = within(complexity).getByTestId("competency-evidence-COMPLEXITY.mean_cyclomatic_complexity");
    expect(mean).toBeVisible();
    expect(mean).toHaveTextContent("Average cyclomatic complexity");
    expect(mean).toHaveTextContent("From CodeDNA COMPLEXITY.mean_cyclomatic_complexity — Average branching per function");
    expect(mean).toHaveTextContent("Cyclomatic complexity (sum over functions)160");
    expect(mean).toHaveTextContent("Functions40");
    expect(mean).toHaveTextContent("Measured value4.00");
    expect(mean).toHaveTextContent("Evidence score75.00");
    expect(mean).toHaveTextContent("Weight in competency60%");
    expect(mean).toHaveTextContent("Scores 100 at 2.00 or less and 0 at 10.00 or more.");
    const share = within(complexity).getByTestId("competency-evidence-COMPLEXITY.complex_function_share");
    expect(share).toHaveTextContent("Measured value5.00%");
    expect(share).toHaveTextContent("Scores 100 at 0.00% or less and 0 at 20.00% or more.");
    expect(complexity).toHaveTextContent("Evidence quality = 50% parse coverage (90.00%) + 25% evidence volume (80.00%) + 25% evidence availability (100.00%)");
    expect(complexity).toHaveTextContent("Level 2 · Established: from 65.00");
    expect(complexity).toHaveTextContent("Level 3 · Strong: from 85.00");

    const hygiene = screen.getByTestId("competency-CODE_HYGIENE");
    await ui.click(within(hygiene).getByText("Why this result (1 evidence)"));
    const syntax = within(hygiene).getByTestId("competency-evidence-CODE_HYGIENE.syntax_error_share");
    expect(within(syntax).getAllByText("Files with syntax errors", { selector: "dt" })).toHaveLength(1);
  });

  it("shows source, DNA and version provenance with navigation back to CodeDNA", async () => {
    respondWith(assessedMatrix);
    render(<CompetencyMatrix projectId={project.id} />);

    const provenance = (await screen.findByRole("heading", { name: "Source and calculation" })).closest("[data-slot=card]") as HTMLElement;
    expect(provenance).toHaveTextContent("Score 80.50 · calculated Oct 12, 2026, 8:00 AM UTC");
    expect(within(provenance).getByRole("link", { name: /Score 80\.50/ })).toHaveAttribute("href", `/app/projects/${project.id}/dna/${assessedMatrix.dna_snapshot_id}`);
    expect(provenance).toHaveTextContent("v1, 12 files");
    expect(provenance).toHaveTextContent("PHP, Python");
    expect(provenance).toHaveTextContent("Competency 1.0.0 · CodeDNA scoring 1.0.0");
    expect(provenance).toHaveTextContent(assessedMatrix.specification_fingerprint);
    expect(screen.getByRole("link", { name: `← ${project.name}` })).toHaveAttribute("href", `/app/projects/${project.id}`);
    expect(screen.getByRole("link", { name: "CodeDNA" })).toHaveAttribute("href", `/app/projects/${project.id}/dna`);
  });

  it("links to the skill gaps", async () => {
    respondWith(assessedMatrix);
    render(<CompetencyMatrix projectId={project.id} />);

    const card = await screen.findByTestId("skill-gap-link");
    expect(within(card).getByRole("link", { name: "View Skill Gaps →" })).toHaveAttribute("href", `/app/projects/${project.id}/skill-gaps`);
  });

  it("shows insufficient and unsupported evidence without a score or level, never 0", async () => {
    respondWith(insufficientMatrix);
    const ui = userEvent.setup();
    render(<CompetencyMatrix projectId={project.id} />);

    expect(await screen.findByTestId("competency-insufficient")).toHaveTextContent("None of the competencies could be assessed");
    expect(screen.getByTestId("competency-summary")).toHaveTextContent("0 of 4 competencies assessed · 3 insufficient evidence · 1 unsupported evidence");
    const complexity = screen.getByTestId("competency-COMPLEXITY_MANAGEMENT");
    expect(within(complexity).getByTestId("competency-status")).toHaveTextContent("Insufficient evidence");
    expect(complexity).toHaveTextContent("This is not a low result.");
    expect(within(complexity).queryByTestId("competency-level")).not.toBeInTheDocument();
    expect(within(complexity).queryByRole("meter")).not.toBeInTheDocument();
    expect(complexity).not.toHaveTextContent("/ 100");
    expect(complexity).toHaveTextContent("Evidence quality34.84%");

    const hygiene = screen.getByTestId("competency-CODE_HYGIENE");
    expect(within(hygiene).getByTestId("competency-status")).toHaveTextContent("Unsupported evidence");
    expect(hygiene).toHaveTextContent("The analyzer cannot measure required evidence for the analyzed languages.");
    await ui.click(within(hygiene).getByText("Why this result (1 evidence)"));
    expect(hygiene).toHaveTextContent("Not supported for the analyzed languages");
    expect(hygiene).toHaveTextContent("Files with syntax errorsNot available");
    expect(hygiene).not.toHaveTextContent("Evidence score");

    await ui.click(within(complexity).getByText("Why this result (2 evidence)"));
    expect(complexity).toHaveTextContent("Needs at least 5 functions to be measured.");
  });

  it("shows partially supported languages as a limitation", async () => {
    const withC: CompetencySnapshot = {
      ...assessedMatrix,
      competencies: assessedMatrix.competencies.map((c) =>
        c.key === "COMPLEXITY_MANAGEMENT" ? { ...c, limitations: [{ language: "c", note: "Parsed without a preprocessor." }] } : c,
      ),
    };
    respondWith(withC);
    render(<CompetencyMatrix projectId={project.id} />);

    expect(await screen.findByTestId("competency-limitations")).toHaveTextContent("C: Parsed without a preprocessor.");
    expect(screen.getAllByTestId("competency-limitations")).toHaveLength(1);
  });

  it("renders backend values without recomputing them", async () => {
    const inconsistent: CompetencySnapshot = {
      ...assessedMatrix,
      competencies: assessedMatrix.competencies.map((c) => (c.key === "TYPE_STRUCTURE" ? { ...c, score: "0.1234", level: "STRONG" } : c)),
    };
    respondWith(inconsistent);
    render(<CompetencyMatrix projectId={project.id} />);

    const types = await screen.findByTestId("competency-TYPE_STRUCTURE");
    expect(types).toHaveTextContent("12.34/ 100");
    expect(within(types).getByTestId("competency-level")).toHaveTextContent("Level 3 · Strong");
  });

  it("explains when no matrix exists, depending on whether a CodeDNA assessment exists", async () => {
    respondWith(null);
    const { unmount } = render(<CompetencyMatrix projectId={project.id} />);
    expect(await screen.findByTestId("competency-empty")).toHaveTextContent("needs a completed static analysis");
    expect(screen.getByRole("link", { name: "Open CodeDNA" })).toHaveAttribute("href", `/app/projects/${project.id}/dna`);
    unmount();

    respondWith(null, { hasDna: true });
    render(<CompetencyMatrix projectId={project.id} />);
    expect(await screen.findByTestId("competency-empty")).toHaveTextContent("A CodeDNA assessment exists, but no competency matrix has been derived from it yet.");
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("treats missing and foreign projects alike and rejects invalid IDs without a request", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const { unmount } = render(<CompetencyMatrix projectId={project.id} />);
    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    unmount();

    fetchMock.mockReset();
    render(<CompetencyMatrix projectId="../admin" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows API errors with their reference and retries", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(503, "SERVICE_UNAVAILABLE", { requestId: "req-777" }));
    const ui = userEvent.setup();
    render(<CompetencyMatrix projectId={project.id} />);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Reference: req-777");
    respondWith(assessedMatrix);
    await ui.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByTestId("competency-COMPLEXITY_MANAGEMENT")).toHaveTextContent("75.00");
  });

  it("sends signed-out users to the login page", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<CompetencyMatrix projectId={project.id} />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("only reads and never uses seniority language", async () => {
    respondWith(assessedMatrix);
    render(<CompetencyMatrix projectId={project.id} />);
    await screen.findByTestId("competency-CODE_HYGIENE");

    expect(fetchMock.mock.calls.every(([, init]) => (init?.method ?? "GET") === "GET")).toBe(true);
    const disclaimer = screen.getByText(/do not establish developer seniority/);
    const rest = (document.body.textContent ?? "").replace(disclaimer.textContent ?? "", "");
    expect(rest).not.toMatch(/junior|middle|senior|expert|seniority|talent|intelligen|you are|developer is/i);
  });
});

describe("competency labels", () => {
  it("labels levels neutrally and rejects unknown values", () => {
    expect(levelLabel("STRONG")).toBe("Strong");
    expect(levelBadge("NOT_ESTABLISHED")).toBe("Level 0 · Not established");
    expect(levelBadge(null)).toBeNull();
    expect(levelBadge("SENIOR")).toBeNull();
    expect(levelMeaning("STRONG")).toBe("The analyzed code demonstrates strong evidence for this competency.");
  });
});
