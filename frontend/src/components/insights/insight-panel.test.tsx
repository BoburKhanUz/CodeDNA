import { act, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { INSIGHT_POLL_MS, InsightPanel } from "@/components/insights/insight-panel";
import type { AiInsight, AiStatus } from "@/lib/api/types";
import { aiDisabled, aiDown, aiReady, failedInsight, INSIGHT_ID, queuedInsight, runningInsight, SUBJECT_ID, succeededInsight } from "@/test/insights";
import { apiErrorResponse, jsonResponse, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
let visibility: DocumentVisibilityState = "visible";

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
  visibility = "visible";
  Object.defineProperty(document, "visibilityState", { configurable: true, get: () => visibility });
});

afterEach(() => {
  vi.useRealTimers();
});

function respondWith({ ai = aiReady, insights = [], post }: { ai?: AiStatus; insights?: AiInsight[]; post?: () => Response }) {
  const queue = [...insights];
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    if (url.endsWith("/ai/status")) return jsonResponse({ data: ai });
    if (init?.method === "POST") return post ? post() : jsonResponse({ data: queuedInsight }, 202);
    if (url.includes(`/insights/${INSIGHT_ID}`)) return jsonResponse({ data: queue.length > 1 ? queue.shift() : queue[0] });
    return jsonResponse({ data: queue[0] ? [queue[0]] : [] });
  });
}

const reads = () => fetchMock.mock.calls.filter(([input]) => String(input).includes(`/insights/${INSIGHT_ID}`)).length;
const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method === "POST");

function renderPanel(canRequest = true) {
  return render(<InsightPanel projectId={project.id} kind="GROWTH_INTERPRETATION" subjectId={SUBJECT_ID} canRequest={canRequest} />);
}

describe("InsightPanel", () => {
  it("shows a loading state first", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    renderPanel();
    expect(screen.getByRole("status", { name: "Loading AI explanation" })).toBeInTheDocument();
  });

  it("says AI is not enabled, without a request button, when the server has it off", async () => {
    respondWith({ ai: aiDisabled });
    renderPanel();
    expect(await screen.findByTestId("insight-disabled")).toHaveTextContent("complete without them");
    expect(screen.queryByTestId("insight-request")).not.toBeInTheDocument();
  });

  it("labels the explanation as AI-generated and renders every claim with its evidence", async () => {
    respondWith({ insights: [succeededInsight] });
    const ui = userEvent.setup();
    renderPanel();

    const ready = await screen.findByTestId("insight-ready");
    expect(screen.getByTestId("insight-ai-label")).toHaveTextContent("AI-generated · not authoritative");
    expect(screen.getByText(/where it and the data differ, the data is right/)).toBeInTheDocument();
    expect(screen.getByTestId("insight-summary")).toHaveTextContent("Modularity improved");
    expect(within(ready).queryByTestId("insight-next_steps")).not.toBeInTheDocument();
    expect(screen.getByTestId("insight-limitations")).toHaveTextContent("Only two analyses were compared.");
    expect(screen.getByTestId("insight-provenance")).toHaveTextContent("Model local-model (ollama) · insight version 1.0.0 · 30 s");

    const points = screen.getByTestId("insight-points");
    await ui.click(within(points).getByText("Evidence (1)"));
    const evidence = within(points).getByTestId("insight-evidence-obs:DNA:modularity");
    expect(evidence).toHaveTextContent("Modularity");
    expect(evidence).toHaveTextContent("IMPROVED");
    expect(screen.queryByTestId("insight-request")).not.toBeInTheDocument();
  });

  it("renders model text as plain text, never as markup", async () => {
    respondWith({ insights: [succeededInsight] });
    renderPanel();
    await screen.findByTestId("insight-ready");
    expect(screen.getByText("<b>Modules</b> are split more clearly.")).toBeInTheDocument();
    expect(document.querySelector("[data-testid='insight-points'] b")).toBeNull();
  });

  it("requests once, shows the pending state and polls until the explanation is ready", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respondWith({ insights: [] });
    const ui = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPanel();

    const button = await screen.findByTestId("insight-request");
    expect(screen.getByTestId("insight-none")).toBeInTheDocument();
    respondWith({ insights: [runningInsight, succeededInsight] });
    await ui.click(button);

    expect(await screen.findByTestId("insight-pending")).toHaveTextContent("Waiting for the local AI model");
    expect(posts()).toHaveLength(1);
    expect(JSON.parse(String(posts()[0][1]?.body))).toEqual({ kind: "GROWTH_INTERPRETATION", subject_id: SUBJECT_ID });
    expect(screen.queryByTestId("insight-request")).not.toBeInTheDocument();

    await act(async () => vi.advanceTimersByTimeAsync(INSIGHT_POLL_MS));
    expect(await screen.findByText(/is writing the explanation/)).toBeInTheDocument();
    await act(async () => vi.advanceTimersByTimeAsync(INSIGHT_POLL_MS));
    expect(await screen.findByTestId("insight-ready")).toBeInTheDocument();
    const settled = reads();
    await act(async () => vi.advanceTimersByTimeAsync(INSIGHT_POLL_MS * 3));
    expect(reads()).toBe(settled);
  });

  it("pauses polling while the page is hidden", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respondWith({ insights: [queuedInsight] });
    renderPanel();
    await screen.findByTestId("insight-pending");

    visibility = "hidden";
    act(() => {
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await act(async () => vi.advanceTimersByTimeAsync(INSIGHT_POLL_MS * 5));
    expect(reads()).toBe(0);

    visibility = "visible";
    act(() => {
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await act(async () => vi.advanceTimersByTimeAsync(INSIGHT_POLL_MS));
    expect(reads()).toBe(1);
  });

  it("shows why a failed explanation failed and offers a retry", async () => {
    respondWith({ insights: [failedInsight] });
    renderPanel();
    expect(await screen.findByTestId("insight-failed")).toHaveTextContent("The local AI service could not be reached.");
    expect(screen.getByTestId("insight-request")).toHaveTextContent("Try again");
  });

  it("disables the request while the local AI service is unavailable", async () => {
    respondWith({ ai: aiDown });
    renderPanel();
    expect(await screen.findByTestId("insight-unavailable")).toBeInTheDocument();
    expect(screen.getByTestId("insight-request")).toBeDisabled();
  });

  it("explains a plan restriction", async () => {
    respondWith({ post: () => apiErrorResponse(402, "FEATURE_NOT_INCLUDED") });
    const ui = userEvent.setup();
    renderPanel();
    await ui.click(await screen.findByTestId("insight-request"));
    expect(await screen.findByTestId("insight-plan")).toHaveTextContent("not included in the current plan");
  });

  it("shows other request errors and keeps the button usable", async () => {
    respondWith({ post: () => apiErrorResponse(503, "AI_UNAVAILABLE") });
    const ui = userEvent.setup();
    renderPanel();
    await ui.click(await screen.findByTestId("insight-request"));
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.getByTestId("insight-request")).toBeEnabled();
  });

  it("stays read-only where requests are not allowed (an archived project)", async () => {
    respondWith({ insights: [failedInsight] });
    renderPanel(false);
    await screen.findByTestId("insight-failed");
    expect(screen.queryByTestId("insight-request")).not.toBeInTheDocument();
  });

  it("shows a load error, and sends an expired session to login", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(500, "INTERNAL_ERROR"));
    const first = renderPanel();
    expect(await screen.findByTestId("insight-error")).toBeInTheDocument();
    first.unmount();

    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    renderPanel();
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
