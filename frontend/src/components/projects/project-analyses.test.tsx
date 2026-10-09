import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ANALYSIS_POLL_MS, ProjectAnalyses } from "@/components/projects/project-analyses";
import type { AnalysisRun } from "@/lib/api/types";
import { apiErrorResponse, jsonResponse, page, project, snapshot } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
let visibility: DocumentVisibilityState = "visible";
const newest = snapshot(2);

function run(status: AnalysisRun["status"], overrides: Partial<AnalysisRun> = {}): AnalysisRun {
  return {
    id: "01m4run0000000000000000001",
    type: "analysis_run",
    project_id: project.id,
    source_snapshot_id: newest.id,
    result_type: "static_analysis",
    status,
    created_at: "2026-10-01T10:00:00Z",
    started_at: null,
    completed_at: null,
    failure: null,
    result: null,
    ...overrides,
  };
}

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token; path=/";
  visibility = "visible";
  Object.defineProperty(document, "visibilityState", { configurable: true, get: () => visibility });
});

afterEach(() => {
  vi.useRealTimers();
});

/** GET /analyses answers the queued pages in order (the last one repeats); POST answers `post`. */
function respondWith(pages: AnalysisRun[][], post: () => Response = () => jsonResponse({ data: run("QUEUED") }, 202)) {
  const queue = [...pages];
  fetchMock.mockImplementation(async (_input, init) => {
    if (init?.method === "POST") return post();
    return jsonResponse(page(queue.length > 1 ? queue.shift()! : queue[0]));
  });
}

const reads = () => fetchMock.mock.calls.filter(([, init]) => (init?.method ?? "GET") === "GET").length;
const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method === "POST");

function renderCard(props: Partial<Parameters<typeof ProjectAnalyses>[0]> = {}) {
  const onCompleted = vi.fn();
  render(
    <ProjectAnalyses
      projectId={project.id}
      newest={newest}
      versions={new Map([[newest.id, 2]])}
      canAnalyze
      onCompleted={onCompleted}
      {...props}
    />,
  );
  return onCompleted;
}

describe("ProjectAnalyses", () => {
  it("asks for source first when there is no snapshot", async () => {
    respondWith([[]]);
    renderCard({ newest: null });
    expect(await screen.findByText(/Add source first/)).toBeInTheDocument();
    expect(screen.queryByTestId("analyze-newest")).not.toBeInTheDocument();
  });

  it("starts a static analysis of the newest snapshot once, follows it, and reports completion", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respondWith([[]]);
    const ui = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    const onCompleted = renderCard();

    expect(await screen.findByText("No analyses yet.")).toBeInTheDocument();
    respondWith([[run("RUNNING")], [run("SUCCEEDED")]]);
    await ui.click(screen.getByTestId("analyze-newest"));

    expect(posts()).toHaveLength(1);
    expect(String(posts()[0][0])).toBe(`/api/v1/projects/${project.id}/analyses`);
    expect(JSON.parse(String(posts()[0][1]?.body))).toEqual({ source_snapshot_id: newest.id, result_type: "static_analysis" });
    expect(await screen.findByTestId("analysis-queued")).toHaveTextContent("Snapshot v2 · static analysis");
    expect(screen.getByTestId("analyze-newest")).toBeDisabled();

    await act(async () => vi.advanceTimersByTimeAsync(ANALYSIS_POLL_MS));
    expect(await screen.findByTestId("analysis-running")).toHaveTextContent("Analyzing");
    await act(async () => vi.advanceTimersByTimeAsync(ANALYSIS_POLL_MS));
    expect(await screen.findByRole("link", { name: "View CodeDNA →" })).toHaveAttribute("href", `/app/projects/${project.id}/dna`);
    expect(onCompleted).toHaveBeenCalledTimes(1);

    const settled = reads();
    await act(async () => vi.advanceTimersByTimeAsync(ANALYSIS_POLL_MS * 3));
    expect(reads()).toBe(settled);
  });

  it("does not offer a second analysis of the same snapshot", async () => {
    respondWith([[run("SUCCEEDED")]]);
    renderCard();
    expect(await screen.findByTestId("analyze-newest-note")).toHaveTextContent("Add new source to assess it again.");
    expect(screen.getByTestId("analyze-newest")).toBeDisabled();
  });

  it("shows why an analysis failed and allows another attempt", async () => {
    respondWith([[run("FAILED", { failure: { code: "ANALYZER_UNAVAILABLE", message: "The analyzer was unavailable." } })]]);
    renderCard();
    expect(await screen.findByText("The analyzer was unavailable.")).toBeInTheDocument();
    expect(screen.getByTestId("analyze-newest")).toBeEnabled();
  });

  it("pauses polling while the page is hidden", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respondWith([[run("QUEUED")]]);
    renderCard();
    await screen.findByTestId("analysis-queued");
    const before = reads();

    visibility = "hidden";
    act(() => {
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await act(async () => vi.advanceTimersByTimeAsync(ANALYSIS_POLL_MS * 5));
    expect(reads()).toBe(before);

    visibility = "visible";
    act(() => {
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await act(async () => vi.advanceTimersByTimeAsync(ANALYSIS_POLL_MS));
    expect(reads()).toBe(before + 1);
  });

  it("shows plan limits and other refusals from the server", async () => {
    respondWith([[]], () => apiErrorResponse(402, "QUOTA_EXCEEDED"));
    const ui = userEvent.setup();
    renderCard();
    await ui.click(await screen.findByTestId("analyze-newest"));
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.getByTestId("analyze-newest")).toBeEnabled();
  });

  it("is read-only for archived projects and labels unscored inventory runs", async () => {
    respondWith([[run("SUCCEEDED", { result_type: "foundation" })]]);
    renderCard({ canAnalyze: false });
    expect(await screen.findByText(/This project is archived/)).toBeInTheDocument();
    expect(screen.queryByTestId("analyze-newest")).not.toBeInTheDocument();
    expect(screen.getByTestId("analysis-succeeded")).toHaveTextContent("inventory only (not scored)");
    expect(screen.queryByRole("link", { name: "View CodeDNA →" })).not.toBeInTheDocument();
  });

  it("sends an expired session to login", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    renderCard();
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
