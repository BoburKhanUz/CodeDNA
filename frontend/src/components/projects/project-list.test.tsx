import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectList } from "@/components/projects/project-list";
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

describe("ProjectList", () => {
  it("shows a loading state, then the projects with their key facts", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(page([project, { ...project, id: "01k6p0a1b2c3d4e5f6g7h8j9zz", name: "Old App", slug: "old-app", status: "ARCHIVED", source_type: "REPOSITORY", language: null }])),
    );
    render(<ProjectList />);

    expect(screen.getByRole("status")).toHaveTextContent("Loading projects…");
    const link = await screen.findByRole("link", { name: "Billing Service" });
    expect(link).toHaveAttribute("href", `/app/projects/${project.id}`);
    expect(screen.getByText("PHP")).toBeInTheDocument();
    expect(screen.getByText("Active")).toBeInTheDocument();
    expect(screen.getByText("Archived")).toBeInTheDocument();
    expect(screen.getByText("Upload")).toBeInTheDocument();
    expect(screen.getByText("Repository")).toBeInTheDocument();
    expect(screen.getAllByText("Oct 7, 2026")).toHaveLength(2);
  });

  it("shows an empty state with a create action", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(page([])));
    render(<ProjectList />);

    expect(await screen.findByRole("heading", { name: "No projects yet" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Create project" })).toHaveAttribute("href", "/app/projects/new");
  });

  it("pages through projects", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse(page([project], { total: 30, last_page: 2 })))
      .mockResolvedValueOnce(jsonResponse(page([{ ...project, id: "01k6p0a1b2c3d4e5f6g7h8j9zz", name: "Page Two" }], { current_page: 2, total: 30, last_page: 2 })));
    render(<ProjectList />);

    await userEvent.setup().click(await screen.findByRole("button", { name: "Next" }));

    expect(await screen.findByText("Page Two")).toBeInTheDocument();
    expect(String(fetchMock.mock.calls[1]?.[0])).toBe("/api/v1/projects?page=2&per_page=25");
    expect(screen.getByText("Page 2 of 2")).toBeInTheDocument();
  });

  it("shows errors with a retry", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR")).mockResolvedValueOnce(jsonResponse(page([project])));
    render(<ProjectList />);

    expect(await screen.findByRole("alert")).toHaveTextContent("CodeDNA is having trouble right now");
    await userEvent.setup().click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByText("Billing Service")).toBeInTheDocument();
  });

  it("sends signed-out visitors to the sign-in page", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<ProjectList />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
