import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AssessmentView } from "@/components/assessment/assessment-view";
import { ChallengeDetail } from "@/components/challenge/challenge-detail";
import { ChallengeList } from "@/components/challenge/challenge-list";
import { CompetencyMatrix } from "@/components/competency/competency-matrix";
import { DnaDashboard } from "@/components/dna/dna-dashboard";
import { ProjectGitHubView } from "@/components/github/project-github";
import { GrowthView } from "@/components/growth/growth-view";
import { HistoryView } from "@/components/history/history-view";
import { ProjectDetail } from "@/components/projects/project-detail";
import { RoadmapView } from "@/components/roadmap/roadmap-view";
import { SkillGapAnalysis } from "@/components/skill-gap/skill-gap-analysis";
import { apiErrorResponse, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router, usePathname: () => "/app/projects", useSearchParams: () => new URLSearchParams() };
});

const fetchMock = vi.fn<typeof fetch>();
const OTHER_ID = "01k6p0a1b2c3d4e5f6g7h8j9ot";

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

/**
 * Phase 22 route x state matrix: every project page handles the same
 * transport states the same way. Behaviour per page on real data is in each
 * page's own tests; this matrix makes sure no page misses a state.
 */
const pages: [string, (id: string) => ReactElement][] = [
  ["project", (id) => <ProjectDetail projectId={id} />],
  ["CodeDNA", (id) => <DnaDashboard projectId={id} />],
  ["CodeDNA snapshot", (id) => <DnaDashboard projectId={id} snapshotId={OTHER_ID} />],
  ["competency matrix", (id) => <CompetencyMatrix projectId={id} />],
  ["skill gaps", (id) => <SkillGapAnalysis projectId={id} />],
  ["AI assessment", (id) => <AssessmentView projectId={id} />],
  ["challenges", (id) => <ChallengeList projectId={id} />],
  ["challenge", (id) => <ChallengeDetail projectId={id} challengeId={OTHER_ID} />],
  ["roadmap", (id) => <RoadmapView projectId={id} />],
  ["roadmap from history", (id) => <RoadmapView projectId={id} roadmapId={OTHER_ID} />],
  ["growth", (id) => <GrowthView projectId={id} />],
  ["growth snapshot", (id) => <GrowthView projectId={id} snapshotId={OTHER_ID} />],
  ["history", (id) => <HistoryView projectId={id} />],
  ["GitHub", (id) => <ProjectGitHubView projectId={id} />],
];

describe.each(pages)("the %s page", (_, page) => {
  it("shows a loading state while waiting", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    render(page(project.id));

    expect(screen.getByRole("status")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("sends a signed-out user to the login page", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(page(project.id));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("shows not found for a 404, the same for missing and foreign resources", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    render(page(project.id));

    expect(await screen.findByRole("heading", { name: /not found/i })).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("server message");
  });

  it("shows a server error with its reference, never the server's text, and retries", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(500, "INTERNAL_ERROR"));
    render(page(project.id));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("11111111-2222-4333-8444-555555555555");
    expect(document.body).not.toHaveTextContent("server message");
    const before = fetchMock.mock.calls.length;
    await userEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThan(before));
  });

  it("explains a network failure without crashing", async () => {
    fetchMock.mockRejectedValue(new TypeError("Failed to fetch"));
    render(page(project.id));

    expect(await screen.findByRole("alert")).toHaveTextContent("Unable to connect to CodeDNA");
  });

  it("refuses a malformed project ID without calling the API", async () => {
    render(page("../../admin"));

    expect(await screen.findByRole("heading", { name: /not found/i })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
