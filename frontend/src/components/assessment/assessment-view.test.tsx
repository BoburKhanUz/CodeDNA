import { act, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AssessmentView, POLL_INTERVAL_MS } from "@/components/assessment/assessment-view";
import type { AiAssessment, Project } from "@/lib/api/types";
import { ASSESSMENT_ID, assessmentSummary, failedAssessment, queuedAssessment, runningAssessment, succeededAssessment } from "@/test/assessment";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";
import { gapsSnapshot, SKILL_GAP_ID, skillGapSummary } from "@/test/skill-gap";

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

afterEach(() => {
  vi.useRealTimers();
});

interface Scenario {
  assessments?: AiAssessment[];
  hasSkillGaps?: boolean;
  projectOverride?: Partial<Project>;
  post?: () => Response;
}

function respondWith({ assessments = [], hasSkillGaps = true, projectOverride = {}, post }: Scenario) {
  const queue = [...assessments];
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    if (init?.method === "POST" && url.endsWith("/assessments")) return post ? post() : jsonResponse({ data: queuedAssessment }, 202);
    if (url.includes(`/assessments/${ASSESSMENT_ID}`)) return jsonResponse({ data: queue.length > 1 ? queue.shift() : queue[0] });
    if (url.includes("/assessments")) return jsonResponse(page(queue[0] ? [assessmentSummary(queue[0])] : []));
    if (url.includes("/skill-gaps")) return jsonResponse(page(hasSkillGaps ? [skillGapSummary(gapsSnapshot)] : []));
    return jsonResponse({ data: { ...project, ...projectOverride } });
  });
}

function posts(): RequestInit[] {
  return fetchMock.mock.calls.filter(([, init]) => init?.method === "POST").map(([, init]) => init as RequestInit);
}

describe("AssessmentView", () => {
  it("shows a skeleton while loading", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    render(<AssessmentView projectId={project.id} />);

    expect(screen.getByRole("status", { name: "Loading AI assessment" })).toBeInTheDocument();
  });

  it("labels the page as an AI-generated interpretation that does not determine scores", async () => {
    respondWith({ assessments: [succeededAssessment] });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-ai-label")).toHaveTextContent("AI-generated interpretation");
    expect(screen.getByTestId("assessment-disclaimer")).toHaveTextContent("The AI does not determine or change any score, level, gap, priority or target");
    expect(screen.getByText(/does not establish developer seniority/)).toBeInTheDocument();
  });

  it("renders the validated output with the evidence every claim cites", async () => {
    respondWith({ assessments: [succeededAssessment] });
    const ui = userEvent.setup();
    render(<AssessmentView projectId={project.id} />);

    const ready = await screen.findByTestId("assessment-ready");
    expect(screen.getByTestId("assessment-summary")).toHaveTextContent("Functions are short and shallow");
    const strengths = within(ready).getByTestId("assessment-strengths");
    expect(within(strengths).getByRole("heading", { name: "Compact functions" })).toBeInTheDocument();
    expect(strengths).toHaveTextContent("Evidence: Function design, Gap: Function design");

    await ui.click(within(strengths).getByText(/Evidence: Function design/));
    const gap = within(strengths).getByTestId("assessment-evidence-gap:FUNCTION_DESIGN");
    expect(gap).toHaveTextContent("Gap: Function design gap:FUNCTION_DESIGN");
    expect(gap).toHaveTextContent("Current score (0–1)0.9000");
    expect(gap).toHaveTextContent("Material gapNo");
    expect(gap).toHaveTextContent("Priority—");

    expect(within(ready).getByTestId("assessment-areas")).toHaveTextContent("Syntax validity");
    expect(within(ready).getByTestId("assessment-insights")).toHaveTextContent("No development insights are stated.");
    expect(within(ready).getByTestId("assessment-limitations")).toHaveTextContent("Testing and security are not measured.");
  });

  it("shows the model, versions, fingerprints, timestamps and lineage", async () => {
    respondWith({ assessments: [succeededAssessment] });
    render(<AssessmentView projectId={project.id} />);

    const provenance = await screen.findByTestId("assessment-provenance");
    expect(screen.getByTestId("assessment-model")).toHaveTextContent("example-model (openai_compatible) · served as example-model-2026-01-01");
    expect(screen.getByTestId("assessment-versions")).toHaveTextContent("Assessment 1.0.0 · prompt 1.0.0 · output assessment/v1 · input assessment-input/1.0.0");
    expect(provenance).toHaveTextContent("c".repeat(64));
    expect(provenance).toHaveTextContent("Oct 12, 2026, 8:00 AM UTC");
    expect(within(provenance).getByRole("link", { name: "Skill gap 1.0.0 · ENGINEERING_STANDARD 1.0.0" })).toHaveAttribute("href", `/app/projects/${project.id}/skill-gaps`);
    expect(within(provenance).getByRole("link", { name: "CodeDNA scoring 1.0.0" })).toHaveAttribute("href", expect.stringContaining("/dna/"));
  });

  it("offers to generate an assessment when there is none, and sends no prompt or model", async () => {
    respondWith({ assessments: [] });
    const ui = userEvent.setup();
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-none")).toHaveTextContent("No AI assessment yet");
    await ui.click(screen.getByRole("button", { name: "Generate AI assessment" }));

    expect(await screen.findByTestId("assessment-queued")).toHaveTextContent("Waiting to start");
    expect(posts()).toHaveLength(1);
    expect(posts()[0].body).toBe("{}");
  });

  it("explains that a skill gap analysis is needed first", async () => {
    respondWith({ assessments: [], hasSkillGaps: false });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-none")).toHaveTextContent("needs a completed static analysis");
    expect(screen.queryByRole("button", { name: "Generate AI assessment" })).not.toBeInTheDocument();
  });

  it("shows that AI is unavailable when the server has it disabled", async () => {
    respondWith({ assessments: [], post: () => apiErrorResponse(409, "AI_ASSESSMENT_DISABLED") });
    const ui = userEvent.setup();
    render(<AssessmentView projectId={project.id} />);

    await ui.click(await screen.findByRole("button", { name: "Generate AI assessment" }));

    expect(await screen.findByTestId("assessment-unavailable")).toHaveTextContent("AI interpretation is not available");
    expect(screen.queryByRole("button", { name: "Generate AI assessment" })).not.toBeInTheDocument();
  });

  it.each([
    ["FEATURE_NOT_INCLUDED", "AI assessment is not part of your plan"],
    ["SUBSCRIPTION_INACTIVE", "AI assessment is not part of your plan"],
    ["QUOTA_EXCEEDED", "Monthly AI assessment limit reached"],
  ] as const)("links to Billing when the plan refuses with %s", async (code, heading) => {
    respondWith({ assessments: [], post: () => apiErrorResponse(402, code) });
    const ui = userEvent.setup();
    render(<AssessmentView projectId={project.id} />);

    await ui.click(await screen.findByRole("button", { name: "Generate AI assessment" }));

    const card = await screen.findByTestId("assessment-plan");
    expect(card).toHaveTextContent(heading);
    expect(within(card).getByRole("link", { name: "See Billing" })).toHaveAttribute("href", "/app/billing");
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("polls a queued assessment until it is ready", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    respondWith({ assessments: [queuedAssessment, runningAssessment, succeededAssessment] });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-queued")).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    });
    expect(await screen.findByTestId("assessment-processing")).toHaveTextContent("Generating the interpretation");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    });
    expect(await screen.findByTestId("assessment-ready")).toBeInTheDocument();

    const reads = fetchMock.mock.calls.filter(([input]) => String(input).includes(`/assessments/${ASSESSMENT_ID}`)).length;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3);
    });
    expect(fetchMock.mock.calls.filter(([input]) => String(input).includes(`/assessments/${ASSESSMENT_ID}`))).toHaveLength(reads);
  });

  it("never lets a late poll of an earlier assessment replace a newer one", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const OLD_GAP = "01k6p0a1b2c3d4e5f6g7h8j9zz";
    const older = { ...queuedAssessment, lineage: { ...queuedAssessment.lineage, skill_gap_snapshot_id: OLD_GAP } };
    const newer = { ...queuedAssessment, id: "01k6p0a1b2c3d4e5f6g7h8j9bb" };
    let finishPoll: (response: Response) => void = () => {};
    let reads = 0;
    fetchMock.mockImplementation(async (input, init) => {
      const url = String(input);
      if (init?.method === "POST" && url.endsWith("/assessments")) return jsonResponse({ data: newer }, 202);
      if (url.includes(`/assessments/${ASSESSMENT_ID}`)) {
        reads += 1;
        return reads === 1 ? jsonResponse({ data: older }) : new Promise<Response>((resolve) => (finishPoll = resolve));
      }
      if (url.includes(`/assessments/${newer.id}`)) return new Promise<Response>(() => {});
      if (url.includes("/assessments")) return jsonResponse(page([assessmentSummary(older)]));
      if (url.includes("/skill-gaps")) return jsonResponse(page([skillGapSummary(gapsSnapshot)]));
      return jsonResponse({ data: project });
    });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-queued")).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    });
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(screen.getByTestId("assessment-request"));
    await act(async () => {
      finishPoll(jsonResponse({ data: { ...succeededAssessment, lineage: older.lineage } }));
      await vi.advanceTimersByTimeAsync(0);
    });

    expect(screen.queryByTestId("assessment-ready")).not.toBeInTheDocument();
    expect(screen.getByTestId("assessment-queued")).toBeInTheDocument();
    expect(screen.queryByTestId("assessment-outdated")).not.toBeInTheDocument();
  });

  it("shows a safe failure without any partial interpretation and allows a new request", async () => {
    respondWith({ assessments: [failedAssessment] });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-failed")).toHaveTextContent("The AI response did not meet the assessment rules and was discarded.");
    expect(screen.queryByTestId("assessment-ready")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("notes when the assessment interprets an older skill gap analysis", async () => {
    respondWith({ assessments: [{ ...succeededAssessment, lineage: { ...succeededAssessment.lineage, skill_gap_snapshot_id: "01k6p0a1b2c3d4e5f6g7h8j9zz" } }] });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByTestId("assessment-outdated")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Interpret the newest analysis" })).toBeInTheDocument();
    expect(SKILL_GAP_ID).not.toBe("01k6p0a1b2c3d4e5f6g7h8j9zz");
  });

  it("offers no request for an archived project", async () => {
    respondWith({ assessments: [], projectOverride: { status: "ARCHIVED" } });
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByText(/This project is archived/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Generate AI assessment" })).not.toBeInTheDocument();
  });

  it("has no free-text input or editing", async () => {
    respondWith({ assessments: [succeededAssessment] });
    render(<AssessmentView projectId={project.id} />);

    await screen.findByTestId("assessment-ready");
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    expect(screen.queryAllByRole("button")).toHaveLength(0);
  });

  it("shows not found for an invalid ID without calling the API", () => {
    render(<AssessmentView projectId="not-a-ulid" />);
    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows not found for a 404", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    render(<AssessmentView projectId={project.id} />);

    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
  });

  it("redirects to sign-in on 401", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<AssessmentView projectId={project.id} />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
