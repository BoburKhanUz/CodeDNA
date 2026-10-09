import { act, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ChallengeDetail, POLL_INTERVAL_MS } from "@/components/challenge/challenge-detail";
import { ChallengeList } from "@/components/challenge/challenge-list";
import { CodeEditor, highlightPython } from "@/components/challenge/code-editor";
import { EvaluationFeedback } from "@/components/challenge/evaluation-feedback";
import type { Challenge, ChallengeSubmission, Project } from "@/lib/api/types";
import { getChallenge } from "@/lib/challenge/client";
import { ruleLimit } from "@/lib/challenge/format";
import { CHALLENGE_ID, challenge, challengeSummary, SUBMISSION_ID, submission, summaryOf } from "@/test/challenge";
import { aiReady } from "@/test/insights";
import { apiErrorResponse, jsonResponse, page, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";
import { gapsSnapshot, skillGapSummary } from "@/test/skill-gap";

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
  challenges?: Challenge[];
  submissions?: ChallengeSubmission[];
  hasSkillGaps?: boolean;
  projectOverride?: Partial<Project>;
  post?: (url: string, body: unknown) => Response;
}

function respondWith({ challenges = [], submissions = [], hasSkillGaps = true, projectOverride = {}, post }: Scenario) {
  const queue = [...submissions];
  const details = [...challenges];
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    if (url.endsWith("/ai/status")) return jsonResponse({ data: aiReady });
    if (url.includes("/insights")) return jsonResponse({ data: [] });
    if (init?.method === "POST") return post ? post(url, JSON.parse(String(init.body))) : jsonResponse({ data: challenge() }, 201);
    if (url.includes(`/submissions/${SUBMISSION_ID}`)) return jsonResponse({ data: queue.length > 1 ? queue.shift() : queue[0] });
    if (url.includes(`/challenges/${CHALLENGE_ID}`)) return jsonResponse({ data: details.length > 1 ? details.shift() : details[0] });
    if (url.includes("/challenges")) return jsonResponse(page(challenges.map((c) => challengeSummary(c))));
    if (url.includes("/skill-gaps")) return jsonResponse(page(hasSkillGaps ? [skillGapSummary(gapsSnapshot)] : []));
    return jsonResponse({ data: { ...project, ...projectOverride } });
  });
}

function posts(): { url: string; body: unknown }[] {
  return fetchMock.mock.calls.filter(([, init]) => init?.method === "POST").map(([input, init]) => ({ url: String(input), body: JSON.parse(String(init?.body)) }));
}

describe("ChallengeList", () => {
  it("explains that challenges never change CodeDNA", async () => {
    respondWith({});
    render(<ChallengeList projectId={project.id} />);

    expect(await screen.findByTestId("challenge-notice")).toHaveTextContent(
      "Completing a challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.",
    );
    expect(screen.getByRole("link", { name: "Growth" })).toHaveAttribute("href", `/app/projects/${project.id}/growth`);
  });

  it("offers a first challenge and opens it after assignment, sending nothing but the request", async () => {
    respondWith({});
    const ui = userEvent.setup();
    render(<ChallengeList projectId={project.id} />);

    expect(await screen.findByTestId("challenges-empty")).toHaveTextContent("No challenges yet");
    await ui.click(screen.getByRole("button", { name: "Get a challenge" }));

    await vi.waitFor(() => expect(router.push).toHaveBeenCalledWith(`/app/projects/${project.id}/challenges/${CHALLENGE_ID}`));
    expect(posts()).toEqual([{ url: `/api/v1/projects/${project.id}/challenges`, body: {} }]);
  });

  it("lists challenges with category, difficulty, language, status, attempts and result", async () => {
    respondWith({ challenges: [challenge({ status: "PASSED", attempts_used: 2, last_result: "PASSED" })] });
    render(<ChallengeList projectId={project.id} />);

    const item = await screen.findByTestId(`challenge-${CHALLENGE_ID}`);
    expect(item).toHaveTextContent("Repair the configuration parser");
    expect(within(item).getByTestId("challenge-status")).toHaveTextContent("Passed");
    expect(item).toHaveTextContent("Code hygiene · Beginner exercise · python · attempts 2 of 5 · last result: passed · assigned Oct 12, 2026");
    expect(within(item).getByRole("link")).toHaveAttribute("href", `/app/projects/${project.id}/challenges/${CHALLENGE_ID}`);
  });

  it("needs a skill gap analysis first", async () => {
    respondWith({ hasSkillGaps: false });
    render(<ChallengeList projectId={project.id} />);

    expect(await screen.findByTestId("challenges-empty")).toHaveTextContent("needs a completed static analysis");
    expect(screen.queryByTestId("challenge-assign")).not.toBeInTheDocument();
  });

  it("shows API refusals such as no eligible gap", async () => {
    respondWith({ post: () => apiErrorResponse(409, "CHALLENGE_NO_ELIGIBLE_GAP") });
    const ui = userEvent.setup();
    render(<ChallengeList projectId={project.id} />);

    await ui.click(await screen.findByRole("button", { name: "Get a challenge" }));
    expect(await screen.findByText("There is no material skill gap with a matching challenge. Run a static analysis first.")).toBeInTheDocument();
  });

  it("keeps archived projects read-only", async () => {
    respondWith({ challenges: [challenge()], projectOverride: { status: "ARCHIVED" } });
    render(<ChallengeList projectId={project.id} />);

    expect(await screen.findByText(/This project is archived/)).toBeInTheDocument();
    expect(screen.queryByTestId("challenge-assign")).not.toBeInTheDocument();
  });

  it("shows not found for a 404 and signs out on 401", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    const { unmount } = render(<ChallengeList projectId={project.id} />);
    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    unmount();

    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<ChallengeList projectId={project.id} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});

describe("ChallengeDetail", () => {
  it("shows the exercise, its acceptance criteria, examples and the hidden test count", async () => {
    respondWith({ challenges: [challenge()] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByRole("heading", { level: 1, name: "Repair the configuration parser" })).toBeInTheDocument();
    expect(screen.getByTestId("challenge-meta")).toHaveTextContent("Code hygiene · Beginner exercise · python3.11 · about 15 minutes · attempts 0 of 5");
    expect(screen.getByTestId("challenge-instructions")).toHaveTextContent("parse_config(text) reads 'key = value' lines into a dictionary.");
    expect(screen.getByTestId("challenge-criteria")).toHaveTextContent("AC1: The file is valid Python with no syntax errors.");
    expect(screen.getByTestId("challenge-examples")).toHaveTextContent('parse_config("name = CodeDNA\\nversion=1") → {"name":"CodeDNA","version":"1"}');
    expect(screen.getByText("Plus 3 hidden tests, reported by status only.")).toBeInTheDocument();
    expect(screen.getByTestId("challenge-notice")).toHaveTextContent("does not immediately change your CodeDNA score or skill gap");
  });

  it("explains why the challenge was selected, with the linked gap and lineage", async () => {
    respondWith({ challenges: [challenge()] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    const why = await screen.findByTestId("challenge-why");
    expect(why).toHaveTextContent("It addresses the skill gap with the highest priority in this analysis.");
    expect(screen.getByTestId("challenge-gap")).toHaveTextContent("Code hygiene: high priority, current 60.00, target 90.00, gap 30.00 points");
    const provenance = screen.getByTestId("challenge-provenance");
    expect(provenance).toHaveTextContent("CODE_HYGIENE_001 1.0.0");
    expect(provenance).toHaveTextContent("Catalog 1.0.0 · challenge-selection/1.0.0 · challenge-evaluation/1.0.0");
    expect(provenance).toHaveTextContent("c".repeat(64));
  });

  it("starts from the starter code and submits exactly the edited source", async () => {
    respondWith({ challenges: [challenge()], submissions: [submission({ status: "QUEUED", evaluation: null, tests: null })], post: () => jsonResponse({ data: submission({ status: "QUEUED", evaluation: null }) }, 202) });
    const ui = userEvent.setup();
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    const editor = await screen.findByRole("textbox", { name: "Solution source" });
    expect(editor).toHaveValue("def parse_config(text)\n    return {}\n");
    await ui.clear(editor);
    await ui.type(editor, "def parse_config(text):{Enter}{Tab}return {{}{Enter}");
    await ui.click(screen.getByRole("button", { name: "Submit attempt" }));

    await vi.waitFor(() => expect(posts()).toHaveLength(1));
    expect(posts()[0]).toEqual({
      url: `/api/v1/projects/${project.id}/challenges/${CHALLENGE_ID}/submissions`,
      body: { language: "python", source: "def parse_config(text):\n    return {}\n" },
    });
    expect(await screen.findByTestId("feedback-pending")).toHaveTextContent("Attempt 1: Waiting for evaluation. This page updates automatically.");
  });

  it("polls a pending attempt until its feedback is ready", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const queued = submission({ status: "QUEUED", evaluation: null, tests: null });
    const running = submission({ status: "RUNNING", evaluation: null, tests: null });
    const done = submission();
    respondWith({
      challenges: [
        challenge({ status: "EVALUATING", recent_attempts: [summaryOf(queued)] }),
        challenge({ status: "ASSIGNED", attempts_used: 1, last_result: "FAILED", recent_attempts: [summaryOf(done)] }),
      ],
      submissions: [queued, running, done, done],
    });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("feedback-pending")).toHaveTextContent("Waiting for evaluation");
    expect(screen.getByRole("button", { name: "Evaluating…" })).toBeDisabled();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    });
    expect(await screen.findByTestId("feedback-pending")).toHaveTextContent("Evaluating");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    });
    expect(await screen.findByTestId("feedback")).toBeInTheDocument();
    // The finished attempt reloads the challenge: its status and attempt count.
    expect(screen.getByTestId("challenge-status")).toHaveTextContent("Open");
  });

  it("keeps polling a pending attempt while an earlier attempt is viewed, and keeps that view", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const EARLIER_ID = "01k6p0a1b2c3d4e5f6g7h8j9ea";
    const earlier = submission({ id: EARLIER_ID, attempt_number: 1, status: "FAILED" });
    const pending = submission({ attempt_number: 2, status: "QUEUED", evaluation: null, tests: null });
    const done = submission({ attempt_number: 2 });
    let finished = false;
    fetchMock.mockImplementation(async (input) => {
      const url = String(input);
      if (url.includes(`/submissions/${EARLIER_ID}`)) return jsonResponse({ data: earlier });
      if (url.includes(`/submissions/${SUBMISSION_ID}`)) {
        const polls = fetchMock.mock.calls.filter(([i]) => String(i).includes(`/submissions/${SUBMISSION_ID}`)).length;
        finished = polls > 2;
        return jsonResponse({ data: finished ? done : pending });
      }
      if (url.includes(`/challenges/${CHALLENGE_ID}`)) {
        return jsonResponse({
          data: finished
            ? challenge({ status: "ASSIGNED", attempts_used: 2, last_result: "FAILED", recent_attempts: [summaryOf(done), summaryOf(earlier)] })
            : challenge({ status: "EVALUATING", attempts_used: 1, recent_attempts: [summaryOf(pending), summaryOf(earlier)] }),
        });
      }
      return jsonResponse({ data: project });
    });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("feedback-pending")).toBeInTheDocument();
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(screen.getByRole("button", { name: /^Attempt 1/ }));
    expect(await screen.findByTestId("feedback")).toBeInTheDocument();
    for (let i = 0; i < 3; i += 1) {
      await act(async () => {
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      });
    }

    // The pending attempt finished in the background: the challenge reloaded, the viewed attempt stayed.
    expect(screen.getByTestId("challenge-status")).toHaveTextContent("Open");
    expect(screen.getByRole("button", { name: /^Attempt 1/ })).toHaveAttribute("aria-current", "true");
    expect(screen.getByRole("button", { name: /^Attempt 2/ })).not.toHaveAttribute("aria-current");
  });

  it("never lets a late poll replace the attempt the user chose to view", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const EARLIER_ID = "01k6p0a1b2c3d4e5f6g7h8j9ea";
    const earlier = submission({ id: EARLIER_ID, attempt_number: 1, status: "FAILED" });
    const pending = submission({ attempt_number: 2, status: "QUEUED", evaluation: null, tests: null });
    let finishPoll: (response: Response) => void = () => {};
    let reads = 0;
    fetchMock.mockImplementation(async (input) => {
      const url = String(input);
      if (url.includes(`/submissions/${EARLIER_ID}`)) return jsonResponse({ data: earlier });
      if (url.includes(`/submissions/${SUBMISSION_ID}`)) {
        reads += 1;
        return reads === 1 ? jsonResponse({ data: pending }) : new Promise<Response>((resolve) => (finishPoll = resolve));
      }
      if (url.includes(`/challenges/${CHALLENGE_ID}`)) {
        return jsonResponse({ data: challenge({ status: "EVALUATING", attempts_used: 1, recent_attempts: [summaryOf(pending), summaryOf(earlier)] }) });
      }
      return jsonResponse({ data: project });
    });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("feedback-pending")).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    });
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(screen.getByRole("button", { name: /^Attempt 1/ }));
    expect(await screen.findByTestId("feedback")).toBeInTheDocument();
    await act(async () => {
      finishPoll(jsonResponse({ data: { ...pending, status: "RUNNING" } }));
      await vi.advanceTimersByTimeAsync(0);
    });

    expect(screen.getByTestId("feedback")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Attempt 1/ })).toHaveAttribute("aria-current", "true");
  });

  it("renders repeated instruction and constraint lines without key collisions", async () => {
    const errors = vi.spyOn(console, "error").mockImplementation(() => {});
    const base = challenge();
    const exercise = { ...base.challenge!, instructions: ["Same line.", "Same line."], constraints: ["Keep it short.", "Keep it short."] };
    respondWith({ challenges: [challenge({ challenge: exercise })], submissions: [submission()] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("challenge-instructions")).toBeInTheDocument();
    expect(within(screen.getByTestId("challenge-instructions")).getAllByText("Same line.")).toHaveLength(2);
    expect(screen.getAllByText("Keep it short.")).toHaveLength(2);
    expect(errors.mock.calls.flat().join(" ")).not.toMatch(/same key/i);
    errors.mockRestore();
  });

  it("shows deterministic feedback: criteria, rules, visible cases with values and hidden cases by status only", async () => {
    const failed = submission();
    respondWith({ challenges: [challenge({ attempts_used: 1, last_result: "FAILED", recent_attempts: [summaryOf(failed)] })], submissions: [failed] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("feedback-verdict")).toHaveTextContent(
      "Attempt 1: not all acceptance criteria are met yet.The code ran to completion. Tests passed: 3 of 5 (1 of 2 examples, 2 of 3 hidden).",
    );
    expect(screen.getByTestId("criterion-AC2")).toHaveTextContent("✗ AC2: The examples parse as expected. not met");
    expect(screen.getByTestId("rule-syntax_valid")).toHaveTextContent("✓ The file has no syntax errors: required");
    expect(screen.getByTestId("case-v1")).toHaveTextContent('expected: {"name":"CodeDNA","version":"1"}observed: {}');
    expect(screen.getByTestId("case-h2")).toHaveTextContent("Hidden test h2: error — raised KeyError");
    expect(screen.getByTestId("case-h2")).not.toHaveTextContent("expected");
    expect(screen.getByTestId("case-v2")).not.toHaveTextContent("observed");
    expect(screen.getByTestId("attempts")).toHaveTextContent("Attempt 1: Not passed — 3 of 5 tests");
  });

  it("offers an AI explanation of an evaluated attempt next to the deterministic feedback", async () => {
    const failed = submission();
    respondWith({ challenges: [challenge({ attempts_used: 1, last_result: "FAILED", recent_attempts: [summaryOf(failed)] })], submissions: [failed] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    const panel = await screen.findByTestId("insight-panel");
    expect(panel).toHaveAttribute("data-kind", "CHALLENGE_FEEDBACK");
    expect(screen.getByTestId("feedback-verdict")).toBeInTheDocument();
    expect(within(panel).getByTestId("insight-request")).toBeEnabled();
  });

  it("shows rule violations by function and line", async () => {
    const failed = submission({
      evaluation: {
        ...submission().evaluation!,
        rules: [{ rule: "max_function_lines", limit: 15, status: "FAILED", observed: 31, violations: [{ name: "summarize_order", line: 1, value: 31 }] }],
      },
    });
    respondWith({ challenges: [challenge({ recent_attempts: [summaryOf(failed)] })], submissions: [failed] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("rule-max_function_lines")).toHaveTextContent("✗ Lines per function: at most 15 — measured 31summarize_order (line 1): 31");
  });

  it("shows a passed challenge as closed and read-only", async () => {
    const passed = submission({ status: "PASSED", evaluation: { ...submission().evaluation!, verdict: "PASSED" } });
    respondWith({ challenges: [challenge({ status: "PASSED", last_result: "PASSED", attempts_used: 1, recent_attempts: [summaryOf(passed)] })], submissions: [passed] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("challenge-closed")).toHaveTextContent("This challenge is passed. It accepts no further attempts.");
    expect(screen.getByTestId("feedback-verdict")).toHaveTextContent("all acceptance criteria are met.");
    expect(screen.queryByTestId("challenge-submit")).not.toBeInTheDocument();
    expect(screen.getByRole("textbox", { name: "Solution source" })).toHaveAttribute("readonly");
  });

  it("shows a challenge whose attempts are used up", async () => {
    respondWith({ challenges: [challenge({ status: "FAILED", attempts_used: 5, last_result: "FAILED" })] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("challenge-closed")).toHaveTextContent("All attempts are used.");
  });

  it("shows an attempt that could not be evaluated", async () => {
    const error = submission({ status: "ERROR", evaluation: null, tests: null, failure: { code: "EVALUATOR_UNAVAILABLE", message: "The challenge evaluator is unavailable. This attempt was not counted." } });
    respondWith({ challenges: [challenge({ last_result: "ERROR", recent_attempts: [summaryOf(error)] })], submissions: [error] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("feedback-error")).toHaveTextContent("Attempt 1 was not evaluated. The challenge evaluator is unavailable. This attempt was not counted.");
  });

  it("refuses to submit while evaluation is unavailable", async () => {
    respondWith({ challenges: [challenge({ evaluation_available: false })] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByTestId("evaluation-unavailable")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Submit attempt" })).toBeDisabled();
  });

  it("shows submission refusals from the API", async () => {
    respondWith({ challenges: [challenge()], post: () => apiErrorResponse(409, "CHALLENGE_EVALUATION_PENDING") });
    const ui = userEvent.setup();
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    await ui.click(await screen.findByRole("button", { name: "Submit attempt" }));
    expect(await screen.findByText("Your previous attempt is still being evaluated.")).toBeInTheDocument();
  });

  it("keeps an archived project's challenge read-only", async () => {
    respondWith({ challenges: [challenge()], projectOverride: { status: "ARCHIVED" } });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    expect(await screen.findByText(/This project is archived/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Submit attempt" })).toBeDisabled();
    expect(screen.getByRole("textbox", { name: "Solution source" })).toHaveAttribute("readonly");
  });

  it("has no AI, hint or chat feature", async () => {
    respondWith({ challenges: [challenge()] });
    render(<ChallengeDetail projectId={project.id} challengeId={CHALLENGE_ID} />);

    await screen.findByTestId("challenge-why");
    expect(screen.queryByText(/\bAI\b|hint|chat|assistant/i)).not.toBeInTheDocument();
    expect(fetchMock.mock.calls.map(([input]) => String(input)).filter((url) => url.includes("assessment"))).toEqual([]);
  });

  it("shows not found for invalid IDs without calling the API", () => {
    render(<ChallengeDetail projectId={project.id} challengeId="../../etc" />);
    expect(screen.getByRole("heading", { name: "Challenge not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("highlightPython", () => {
  it("marks keywords, strings, comments and numbers without changing the text", () => {
    const source = "def f(x):  # note\n    return 'a' + str(42)\n";
    const { container } = render(<pre>{highlightPython(source)}</pre>);

    expect(container.textContent).toBe(source);
    expect(Array.from(container.querySelectorAll("[data-token]")).map((n) => [n.getAttribute("data-token"), n.textContent])).toEqual([
      ["keyword", "def"],
      ["comment", "# note"],
      ["keyword", "return"],
      ["string", "'a'"],
      ["number", "42"],
    ]);
  });

  it("renders markup in source as text, never as HTML", () => {
    const { container } = render(<pre>{highlightPython("x = '<img src=x onerror=alert(1)>'")}</pre>);

    expect(container.querySelector("img")).toBeNull();
    expect(container.textContent).toBe("x = '<img src=x onerror=alert(1)>'");
  });
});

describe("CodeEditor", () => {
  function Editor() {
    const [value, setValue] = useState("x");
    return (
      <>
        <CodeEditor value={value} onChange={setValue} label="Source" />
        <button type="button">Next</button>
      </>
    );
  }

  it("indents with Tab, and lets keyboard users leave with Escape then Tab", async () => {
    const ui = userEvent.setup();
    render(<Editor />);
    const editor = screen.getByRole("textbox", { name: "Source" });
    expect(editor).toHaveAccessibleDescription("Tab inserts four spaces. Press Escape, then Tab, to leave the editor.");

    await ui.click(editor);
    await ui.keyboard("{End}{Tab}");
    expect(editor).toHaveValue("x    ");
    expect(editor).toHaveFocus();

    await ui.keyboard("{Escape}{Tab}");
    expect(editor).toHaveValue("x    ");
    expect(screen.getByRole("button", { name: "Next" })).toHaveFocus();

    await ui.click(editor);
    await ui.keyboard("{End}{Tab}");
    expect(editor).toHaveValue("x        ");
    expect(editor).toHaveFocus();
  });
});

describe("EvaluationFeedback", () => {
  it("says when a structural rule was not checked because the file does not parse", () => {
    const failed = submission({
      evaluation: {
        ...submission().evaluation!,
        rules: [
          { rule: "syntax_valid", limit: true, status: "FAILED", line: 3 },
          { rule: "max_function_lines", limit: 15, status: "FAILED", not_evaluated: true },
        ],
      },
    });
    render(<EvaluationFeedback submission={failed} />);

    expect(screen.getByTestId("rule-syntax_valid")).toHaveTextContent("syntax error on line 3");
    expect(screen.getByTestId("rule-max_function_lines")).toHaveTextContent("Lines per function: at most 15 — not checked because the file does not parse");
  });
});

describe("challenge helpers", () => {
  it("phrase rule limits", () => {
    expect([ruleLimit("min_classes", 2), ruleLimit("max_function_lines", 15), ruleLimit("syntax_valid", true)]).toEqual(["at least 2", "at most 15", "required"]);
  });

  it("refuse malformed IDs before calling the API", async () => {
    await expect(getChallenge(project.id, "../../etc")).rejects.toThrow("Invalid challenge ID.");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("CodeEditor (read-only)", () => {
  it("does not change a read-only editor's text on Tab", async () => {
    const ui = userEvent.setup();
    const onChange = vi.fn();
    render(<CodeEditor value="x" onChange={onChange} label="Source" readOnly />);

    await ui.click(screen.getByRole("textbox", { name: "Source" }));
    await ui.keyboard("{Tab}");
    expect(onChange).not.toHaveBeenCalled();
  });
});
