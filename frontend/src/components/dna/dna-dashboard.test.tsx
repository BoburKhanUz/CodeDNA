import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DnaDashboard } from "@/components/dna/dna-dashboard";
import type { DnaSnapshot } from "@/lib/api/types";
import { analysisRun, DNA_ID, insufficientSnapshot, readySnapshot, summary } from "@/test/dna";
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

function respondWith(options: { snapshot?: DnaSnapshot | null; history?: DnaSnapshot[]; runs?: ReturnType<typeof analysisRun>[] } = {}) {
  const snapshot = options.snapshot === undefined ? readySnapshot : options.snapshot;
  const history = options.history ?? (snapshot ? [snapshot] : []);
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    if (url.includes("/dna/")) return snapshot ? jsonResponse({ data: snapshot }) : apiErrorResponse(404, "RESOURCE_NOT_FOUND");
    if (url.includes("/dna")) return jsonResponse(page(history.map((item) => summary(item))));
    if (url.includes("/analyses")) return jsonResponse(page(options.runs ?? []));
    return jsonResponse({ data: project });
  });
}

function requestedUrls(): string[] {
  return fetchMock.mock.calls.map(([input]) => String(input));
}

describe("DnaDashboard", () => {
  it("shows a skeleton while loading", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    render(<DnaDashboard projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading CodeDNA" })).toBeInTheDocument();
  });

  it("renders the newest assessment exactly as the API returns it", async () => {
    respondWith();
    render(<DnaDashboard projectId={project.id} />);

    expect(await screen.findByRole("heading", { level: 1, name: "CodeDNA" })).toBeInTheDocument();
    expect(screen.getByText("Billing Service: deterministic analysis of the analyzed source code.")).toBeInTheDocument();
    expect(screen.getByText(/Scoring version 1\.0\.0 · calculated Oct 12, 2026, 8:00 AM UTC · source snapshot v1/)).toBeInTheDocument();
    expect(screen.getByTestId("dna-overall-score")).toHaveTextContent("80.50/ 100");
    expect(screen.getByRole("meter", { name: "CodeDNA score" })).toHaveAttribute("aria-valuenow", "80.5");
    expect(screen.getByTestId("dna-data-quality")).toHaveTextContent("90.00%");
    expect(screen.getByText(/Based on the availability and completeness of analyzed code evidence/)).toBeInTheDocument();
    expect(screen.queryByText(/confidence|probability/i)).not.toBeInTheDocument();
    expect(requestedUrls()).toContain(`/api/v1/projects/${project.id}/dna/${DNA_ID}`);
    expect(requestedUrls().some((url) => url.includes("/analyses"))).toBe(false);
  });

  it("renders every dimension with its score, weight, contribution and evidence", async () => {
    respondWith();
    const ui = userEvent.setup();
    render(<DnaDashboard projectId={project.id} />);

    const complexity = await screen.findByTestId("dna-dimension-COMPLEXITY");
    expect(within(complexity).getByRole("heading", { name: "Complexity" })).toBeInTheDocument();
    expect(within(complexity).getByText("How much branching the functions contain, from cyclomatic complexity and nesting.")).toBeInTheDocument();
    expect(within(complexity).getByText("81.25")).toBeInTheDocument();
    expect(within(complexity).getByText("40%")).toBeInTheDocument();
    expect(within(complexity).getByText("32.50 points")).toBeInTheDocument();
    expect(screen.getByTestId("dna-dimension-STRUCTURE")).toHaveTextContent("90.00");
    expect(screen.getByTestId("dna-dimension-CODE_HYGIENE")).toHaveTextContent("60.00");

    await ui.click(within(complexity).getByText("Evidence (3 measurements)"));
    const mean = within(complexity).getByTestId("dna-component-mean_cyclomatic_complexity");
    expect(within(mean).getByText("Average cyclomatic complexity")).toBeVisible();
    expect(mean).toHaveTextContent("Cyclomatic complexity (sum over functions)160");
    expect(mean).toHaveTextContent("Functions40");
    expect(mean).toHaveTextContent("Measured value4.00");
    expect(mean).toHaveTextContent("Component score75.00");
    expect(mean).toHaveTextContent("Scores 100 at 2.00 or less and 0 at 10.00 or more.");
    const share = within(complexity).getByTestId("dna-component-complex_function_share");
    expect(share).toHaveTextContent("Functions above the complexity threshold2");
    expect(share).toHaveTextContent("Measured value5.00%");

    const hygiene = screen.getByTestId("dna-dimension-CODE_HYGIENE");
    await ui.click(within(hygiene).getByText("Evidence (1 measurements)"));
    const syntax = within(hygiene).getByTestId("dna-component-syntax_error_share");
    expect(syntax).toHaveTextContent("Files with syntax errors1Parsed files9Measured value10.00%");
    // Shown once although it is both numerator and denominator.
    expect(within(syntax).getAllByText("Files with syntax errors", { selector: "dt" })).toHaveLength(1);
  });

  it("links to the Competency Matrix", async () => {
    respondWith();
    render(<DnaDashboard projectId={project.id} />);

    const card = await screen.findByTestId("competency-link");
    expect(within(card).getByRole("link", { name: "View Competency Matrix →" })).toHaveAttribute("href", `/app/projects/${project.id}/competencies`);
  });

  it("links to Growth", async () => {
    respondWith();
    render(<DnaDashboard projectId={project.id} />);

    const card = await screen.findByTestId("growth-link");
    expect(within(card).getByRole("link", { name: "View Growth →" })).toHaveAttribute("href", `/app/projects/${project.id}/growth`);
    expect(card).toHaveTextContent("Only new code analysis shows change.");
  });

  it("shows the source, run and scoring version", async () => {
    respondWith();
    render(<DnaDashboard projectId={project.id} />);

    const source = (await screen.findByRole("heading", { name: "Source and calculation" })).closest("[data-slot=card]") as HTMLElement;
    expect(source).toHaveTextContent("v1, 12 files, uploaded Oct 12, 2026, 7:55 AM UTC");
    expect(source).toHaveTextContent("9 parsed of 10 analyzable");
    expect(source).toHaveTextContent(`${readySnapshot.analysis_run_id} · SUCCEEDED`);
    expect(source).toHaveTextContent("1.0.0 (metrics 1.0, analyzer 0.2.0)");
    expect(within(source).getByRole("link", { name: "Project and source snapshots" })).toHaveAttribute("href", `/app/projects/${project.id}`);
  });

  it("shows insufficient data instead of a score of 0", async () => {
    respondWith({ snapshot: insufficientSnapshot });
    const ui = userEvent.setup();
    render(<DnaDashboard projectId={project.id} />);

    const overall = await screen.findByTestId("dna-overall-score");
    expect(overall).toHaveTextContent("Insufficient data");
    expect(overall).toHaveTextContent("at least 2 dimensions must be scored; 1 could be");
    expect(overall).toHaveTextContent("This is not a score of 0.");
    expect(screen.queryByRole("meter", { name: "CodeDNA score" })).not.toBeInTheDocument();
    expect(screen.getByTestId("dna-data-quality")).toHaveTextContent("38.41%");

    const complexity = screen.getByTestId("dna-dimension-COMPLEXITY");
    expect(complexity).toHaveTextContent("Not scored");
    expect(complexity).toHaveTextContent("Insufficient evidence: this dimension is not part of the overall score.");
    expect(within(complexity).queryByText("0.00")).not.toBeInTheDocument();
    expect(complexity).toHaveTextContent("Contribution—");
    await ui.click(within(complexity).getByText("Evidence (3 measurements)"));
    const mean = within(complexity).getByTestId("dna-component-mean_cyclomatic_complexity");
    expect(mean).toHaveTextContent("Insufficient evidence");
    expect(mean).toHaveTextContent("Functions3");
    expect(mean).toHaveTextContent("Needs at least 5 functions to be measured.");
    expect(mean).not.toHaveTextContent("Measured value");
    // A dimension that was scored at 0 is shown as 0, because the engine said so.
    expect(screen.getByTestId("dna-dimension-CODE_HYGIENE")).toHaveTextContent("0.00");
  });

  it("marks unsupported and missing evidence and never shows them as 0", async () => {
    const unsupported: DnaSnapshot = {
      ...readySnapshot,
      dimensions: readySnapshot.dimensions.map((dimension) =>
        dimension.dimension !== "STRUCTURE"
          ? dimension
          : {
              ...dimension,
              components: dimension.components.map((c) =>
                c.key === "large_type_share"
                  ? { ...c, status: "UNSUPPORTED" as const, value: null, score: null, denominator: [{ metric: "metrics.overall.types", value: null }] }
                  : c.key === "long_parameter_list_share"
                    ? { ...c, status: "MISSING" as const, value: null, score: null, numerator: [{ metric: "findings.by_rule.structure/parameter-count", value: null }] }
                    : c,
              ),
            },
      ),
    };
    respondWith({ snapshot: unsupported });
    const ui = userEvent.setup();
    render(<DnaDashboard projectId={project.id} />);

    const structure = await screen.findByTestId("dna-dimension-STRUCTURE");
    await ui.click(within(structure).getByText("Evidence (3 measurements)"));
    const types = within(structure).getByTestId("dna-component-large_type_share");
    expect(types).toHaveTextContent("Not supported for the analyzed languages");
    expect(types).toHaveTextContent("TypesNot available");
    expect(types).toHaveTextContent("(optional)");
    const parameters = within(structure).getByTestId("dna-component-long_parameter_list_share");
    expect(parameters).toHaveTextContent("Not available in the analysis result");
    expect(parameters).toHaveTextContent("Functions above the parameter thresholdNot available");
  });

  it("displays backend values even when they would not match a client-side formula", async () => {
    // The frontend never recomputes: an (impossible) inconsistent payload is shown as given.
    const inconsistent: DnaSnapshot = {
      ...readySnapshot,
      overall_score: "0.1234",
      dimensions: readySnapshot.dimensions.map((d) => (d.dimension === "COMPLEXITY" ? { ...d, contribution: "0.0101" } : d)),
    };
    respondWith({ snapshot: inconsistent });
    render(<DnaDashboard projectId={project.id} />);

    expect(await screen.findByTestId("dna-overall-score")).toHaveTextContent("12.34");
    expect(screen.getByTestId("dna-dimension-COMPLEXITY")).toHaveTextContent("1.01 points");
  });

  it("explains that no assessment exists yet, without inventing progress", async () => {
    respondWith({ snapshot: null, history: [] });
    render(<DnaDashboard projectId={project.id} />);

    const empty = await screen.findByTestId("dna-empty");
    expect(empty).toHaveTextContent("No CodeDNA assessment is available yet.");
    expect(empty).toHaveTextContent("The project needs a completed static analysis of a source snapshot");
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
    expect(screen.queryByText(/%/)).not.toBeInTheDocument();
    expect(requestedUrls().some((url) => url.includes("/dna/"))).toBe(false);
  });

  it("reports a running or completed analysis exactly as the API states it", async () => {
    respondWith({ snapshot: null, history: [], runs: [analysisRun({ result_type: "foundation", status: "SUCCEEDED" }), analysisRun()] });
    const { unmount } = render(<DnaDashboard projectId={project.id} />);
    expect(await screen.findByTestId("dna-empty")).toHaveTextContent("A static analysis is in progress.");
    expect(screen.getByTestId("dna-empty")).toHaveTextContent("The newest static analysis is running");
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
    unmount();

    respondWith({ snapshot: null, history: [], runs: [analysisRun({ status: "SUCCEEDED", completed_at: "2026-10-12T08:00:00Z" })] });
    render(<DnaDashboard projectId={project.id} />);
    expect(await screen.findByTestId("dna-empty")).toHaveTextContent("The static analysis has completed, but no assessment is available yet.");
  });

  it("lists earlier assessments and links to them", async () => {
    const older = { ...insufficientSnapshot, id: "01k6p0a1b2c3d4e5f6g7h8j9db", created_at: "2026-10-01T08:00:00Z" };
    respondWith({ history: [readySnapshot, older] });
    render(<DnaDashboard projectId={project.id} />);

    const table = await screen.findByRole("table");
    expect(table).toHaveTextContent("Oct 12, 2026, 8:00 AM UTC (shown)");
    expect(within(table).getByRole("link", { name: "Oct 1, 2026, 8:00 AM UTC" })).toHaveAttribute(
      "href",
      `/app/projects/${project.id}/dna/${older.id}`,
    );
    expect(table).toHaveTextContent("Insufficient data");
  });

  it("loads a specific assessment and handles one that does not exist", async () => {
    respondWith({ snapshot: null, history: [readySnapshot] });
    render(<DnaDashboard projectId={project.id} snapshotId="01k6p0a1b2c3d4e5f6g7h8j9zz" />);

    expect(await screen.findByRole("heading", { name: "Assessment not found" })).toBeInTheDocument();
    expect(requestedUrls()).toContain(`/api/v1/projects/${project.id}/dna/01k6p0a1b2c3d4e5f6g7h8j9zz`);
  });

  it("treats missing and foreign projects alike and rejects invalid IDs without a request", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const { unmount } = render(<DnaDashboard projectId={project.id} />);
    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    unmount();

    fetchMock.mockReset();
    render(<DnaDashboard projectId="../../admin" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows API errors with their reference and can retry", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(500, "INTERNAL_ERROR", { requestId: "req-123" }));
    const ui = userEvent.setup();
    render(<DnaDashboard projectId={project.id} />);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("CodeDNA is having trouble right now.");
    expect(alert).toHaveTextContent("Reference: req-123");
    expect(alert).not.toHaveTextContent("server message");

    respondWith();
    await ui.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByTestId("dna-overall-score")).toHaveTextContent("80.50");
  });

  it("sends signed-out users to the login page", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<DnaDashboard projectId={project.id} />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("only reads: no request other than GET", async () => {
    respondWith();
    render(<DnaDashboard projectId={project.id} />);
    await screen.findByTestId("dna-overall-score");

    expect(fetchMock.mock.calls.every(([, init]) => (init?.method ?? "GET") === "GET")).toBe(true);
    const disclaimer = screen.getByText(/does not infer developer seniority/);
    const rest = (document.body.textContent ?? "").replace(disclaimer.textContent ?? "", "");
    expect(rest).not.toMatch(/junior|middle|senior|expert|seniority|talent|intelligence/i);
  });
});
