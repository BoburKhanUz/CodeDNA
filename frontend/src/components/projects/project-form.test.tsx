import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectForm } from "@/components/projects/project-form";
import { apiErrorResponse, jsonResponse, project } from "@/test/responses";
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
  document.cookie = "XSRF-TOKEN=token; path=/";
});

const body = () => JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body));

describe("ProjectForm", () => {
  it("derives the slug from the name until the slug is edited", async () => {
    const ui = userEvent.setup();
    render(<ProjectForm />);

    await ui.type(screen.getByLabelText("Name"), "Billing Service");
    expect(screen.getByLabelText("Slug")).toHaveValue("billing-service");

    await ui.clear(screen.getByLabelText("Slug"));
    await ui.type(screen.getByLabelText("Slug"), "billing");
    await ui.type(screen.getByLabelText("Name"), " API");
    expect(screen.getByLabelText("Slug")).toHaveValue("billing");
  });

  it("validates before calling the API", async () => {
    const ui = userEvent.setup();
    render(<ProjectForm />);

    await ui.click(screen.getByRole("radio", { name: /Repository/ }));
    await ui.type(screen.getByLabelText("Repository URL"), "http://git.example.test/a.git");
    await ui.type(screen.getByLabelText("Default branch"), "bad..branch");
    await ui.click(screen.getByRole("button", { name: "Create project" }));

    expect(screen.getByText("Enter a project name.")).toBeInTheDocument();
    expect(screen.getByText("Enter a slug.")).toBeInTheDocument();
    expect(screen.getByText("Enter a full URL starting with https://.")).toBeInTheDocument();
    expect(screen.getByText("Enter a valid branch name, such as main.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("creates an upload project and opens it", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: project }, 201));
    render(<ProjectForm />);

    await ui.type(screen.getByLabelText("Name"), "Billing Service");
    await ui.selectOptions(screen.getByLabelText("Language"), "php");
    await ui.click(screen.getByRole("button", { name: "Create project" }));

    expect(body()).toEqual({
      name: "Billing Service",
      slug: "billing-service",
      source_type: "UPLOAD",
      description: null,
      default_branch: null,
      language: "php",
    });
    expect(router.push).toHaveBeenCalledWith(`/app/projects/${project.id}`);
  });

  it("sends the repository URL only for repository projects", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { ...project, source_type: "REPOSITORY" } }, 201));
    render(<ProjectForm />);

    expect(screen.queryByLabelText("Repository URL")).not.toBeInTheDocument();
    await ui.type(screen.getByLabelText("Name"), "Mirror");
    await ui.click(screen.getByRole("radio", { name: /Repository/ }));
    await ui.type(screen.getByLabelText("Repository URL"), "https://git.example.test/acme/mirror.git");
    await ui.click(screen.getByRole("button", { name: "Create project" }));

    expect(body()).toMatchObject({ source_type: "REPOSITORY", repository_url: "https://git.example.test/acme/mirror.git" });
  });

  it("shows server validation errors on the right field", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(
      apiErrorResponse(422, "VALIDATION_FAILED", { fields: { slug: ["You already have a project with this slug."] } }),
    );
    render(<ProjectForm />);

    await ui.type(screen.getByLabelText("Name"), "Billing");
    await ui.click(screen.getByRole("button", { name: "Create project" }));

    expect(await screen.findByText("You already have a project with this slug.")).toBeInTheDocument();
    expect(screen.getByLabelText("Slug")).toHaveAttribute("aria-invalid", "true");
    expect(router.push).not.toHaveBeenCalled();
  });
});
