import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectDetail } from "@/components/projects/project-detail";
import type { DnaSnapshotSummary } from "@/lib/api/types";
import { insufficientSnapshot, readySnapshot, summary } from "@/test/dna";
import { apiErrorResponse, jsonResponse, page, project, snapshot } from "@/test/responses";
import { resetRouter } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token; path=/";
});

function respondWith(
  projectData = project,
  snapshots = page([snapshot(2), snapshot(1, { primary_language: null })]),
  dna: DnaSnapshotSummary[] = [],
) {
  fetchMock.mockImplementation(async (input) => {
    const url = String(input);
    if (url.includes("/dna")) return jsonResponse(page(dna));
    return url.includes("/source-snapshots") ? jsonResponse(snapshots) : jsonResponse({ data: projectData });
  });
}

describe("ProjectDetail", () => {
  it("shows the project and its snapshot history as plain text", async () => {
    respondWith({ ...project, description: "<img src=x onerror=alert(1)>" });
    const { container } = render(<ProjectDetail projectId={project.id} />);

    expect(screen.getByRole("status")).toHaveTextContent("Loading project…");
    expect(await screen.findByRole("heading", { name: "Billing Service" })).toBeInTheDocument();
    expect(screen.getByText("<img src=x onerror=alert(1)>")).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
    expect(screen.getByText("v2")).toBeInTheDocument();
    expect(screen.getByText("v1")).toBeInTheDocument();
    expect(screen.getAllByText("aaaaaaaaaaaa")).toHaveLength(2);
    expect(screen.getAllByText("2.0 KB")).toHaveLength(2);
    expect(screen.getByLabelText("ZIP archive")).toBeInTheDocument();
  });

  it("archives only after confirmation, then hides the upload", async () => {
    respondWith(project, page([]));
    const ui = userEvent.setup();
    render(<ProjectDetail projectId={project.id} />);

    await ui.click(await screen.findByRole("button", { name: "Archive project" }));
    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === "POST")).toHaveLength(0);

    fetchMock.mockImplementationOnce(async () => jsonResponse({ data: { ...project, status: "ARCHIVED" } }));
    await ui.click(screen.getByRole("button", { name: "Yes, archive this project" }));

    expect(await screen.findByText("This project is archived. It keeps its history but accepts no new source.")).toBeInTheDocument();
    expect(screen.queryByLabelText("ZIP archive")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Archive project" })).not.toBeInTheDocument();
    const archiveCall = fetchMock.mock.calls.find(([, init]) => init?.method === "POST");
    expect(String(archiveCall?.[0])).toBe(`/api/v1/projects/${project.id}/archive`);
  });

  it("does not offer uploads for repository projects", async () => {
    respondWith({ ...project, source_type: "REPOSITORY", repository_url: "https://git.example.test/a.git" }, page([]));
    render(<ProjectDetail projectId={project.id} />);

    expect(await screen.findByText(/Importing from repositories is not available yet/)).toBeInTheDocument();
    expect(screen.getByText("https://git.example.test/a.git")).toBeInTheDocument();
    expect(screen.queryByLabelText("ZIP archive")).not.toBeInTheDocument();
  });

  it("treats missing and foreign projects alike", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(404, "RESOURCE_NOT_FOUND"));
    render(<ProjectDetail projectId={project.id} />);

    expect(await screen.findByRole("heading", { name: "Project not found" })).toBeInTheDocument();
  });

  it("does not call the API for an invalid project ID", () => {
    render(<ProjectDetail projectId="../../admin" />);

    expect(screen.getByRole("heading", { name: "Project not found" })).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows the newest CodeDNA assessment and links to the dashboard", async () => {
    respondWith(project, page([]), [summary(readySnapshot)]);
    render(<ProjectDetail projectId={project.id} />);

    const card = await screen.findByTestId("dna-summary");
    expect(within(card).getByRole("heading", { name: "CodeDNA" })).toBeInTheDocument();
    expect(await within(card).findByText("80.50")).toBeInTheDocument();
    expect(card).toHaveTextContent("Data quality: 90.00%");
    expect(within(card).getByRole("link", { name: "View CodeDNA →" })).toHaveAttribute("href", `/app/projects/${project.id}/dna`);
    expect(fetchMock.mock.calls.map(([input]) => String(input))).toContain(`/api/v1/projects/${project.id}/dna?page=1&per_page=1`);
  });

  it("shows when no CodeDNA assessment exists, or only insufficient data", async () => {
    respondWith(project, page([]));
    const { unmount } = render(<ProjectDetail projectId={project.id} />);
    const card = await screen.findByTestId("dna-summary");
    expect(await within(card).findByText("No assessment available.")).toBeInTheDocument();
    expect(within(card).getByRole("link", { name: "View details →" })).toHaveAttribute("href", `/app/projects/${project.id}/dna`);
    unmount();

    respondWith(project, page([]), [summary(insufficientSnapshot)]);
    render(<ProjectDetail projectId={project.id} />);
    const insufficient = await screen.findByTestId("dna-summary");
    expect(await within(insufficient).findByText("Insufficient data")).toBeInTheDocument();
    expect(insufficient).toHaveTextContent("Data quality: 38.41%");
    expect(insufficient).not.toHaveTextContent("0.00");
  });
});
