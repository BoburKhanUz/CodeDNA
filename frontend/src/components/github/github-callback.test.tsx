import { render, screen } from "@testing-library/react";
import { StrictMode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { GitHubCallback } from "@/components/github/github-callback";
import { apiErrorResponse, jsonResponse, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
const STATE = "Q2xpZW50U3RhdGVWYWx1ZUZvclRlc3RpbmdQdXJwb3Nlc19f".slice(0, 43);

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

describe("GitHubCallback", () => {
  it("sends the state and code exactly once, then returns to the project", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: { connected: true, project_id: project.id } }));
    // StrictMode runs effects twice in development: the code must still be sent once.
    const { rerender } = render(
      <StrictMode>
        <GitHubCallback code="gh-code" state={STATE} />
      </StrictMode>,
    );
    rerender(
      <StrictMode>
        <GitHubCallback code="gh-code" state={STATE} />
      </StrictMode>,
    );

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith(`/app/projects/${project.id}/github?github=connected`));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toBe("/api/v1/github/callback");
    expect(JSON.parse(String(init?.body))).toEqual({ state: STATE, code: "gh-code" });
    expect(screen.getByRole("status", { name: "Connecting GitHub" })).not.toHaveTextContent("gh-code");
  });

  it("goes to the project list when no project was named, or an unexpected one comes back", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: { connected: true, project_id: "../../admin" } }));
    render(<GitHubCallback code="gh-code" state={STATE} />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/app/projects"));
  });

  it("explains a declined or incomplete authorization without calling the API", () => {
    const { unmount } = render(<GitHubCallback error="access_denied" state={STATE} />);
    expect(screen.getByTestId("github-callback-error")).toHaveTextContent("The authorization was cancelled on GitHub. Nothing was changed.");
    unmount();
    render(<GitHubCallback code="gh-code" />);
    expect(screen.getByTestId("github-callback-error")).toHaveTextContent("The response from GitHub was incomplete.");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows a refused state as a safe message", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(422, "GITHUB_STATE_INVALID"));
    render(<GitHubCallback code="gh-code" state={STATE} />);

    expect(await screen.findByTestId("github-callback-error")).toHaveTextContent("This GitHub sign-in link is invalid, expired or already used. Start again.");
    expect(document.body.textContent).not.toContain("gh-code");
    expect(document.body.textContent).not.toContain(STATE);
  });

  it("sends a signed-out user to the login page", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<GitHubCallback code="gh-code" state={STATE} />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
