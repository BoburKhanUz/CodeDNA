import { render, screen } from "@testing-library/react";
import { StrictMode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProviderCallback } from "@/components/repository-providers/provider-callback";
import { apiErrorResponse, jsonResponse, project } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();
const STATE = "A".repeat(43);

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

describe("ProviderCallback", () => {
  it("sends the state and code to its own provider exactly once, then returns to the project", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: { provider: "gitlab", connected: true, project_id: project.id } }));
    const { rerender } = render(
      <StrictMode>
        <ProviderCallback provider="gitlab" code="gl-code" state={STATE} />
      </StrictMode>,
    );
    rerender(
      <StrictMode>
        <ProviderCallback provider="gitlab" code="gl-code" state={STATE} />
      </StrictMode>,
    );

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith(`/app/projects/${project.id}/repositories?provider=connected`));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toBe("/api/v1/repository-providers/gitlab/callback");
    expect(JSON.parse(String(init?.body))).toEqual({ state: STATE, code: "gl-code" });
    expect(screen.getByRole("status", { name: "Connecting GitLab" })).not.toHaveTextContent("gl-code");
  });

  it("goes to the project list when no project, or an unexpected one, comes back", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: { connected: true, project_id: "../../admin" } }));
    render(<ProviderCallback provider="bitbucket" code="c" state={STATE} />);
    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/app/projects"));
  });

  it("sends nothing when the provider declined or the response is incomplete", () => {
    const { unmount } = render(<ProviderCallback provider="bitbucket" error="access_denied" />);
    expect(screen.getByTestId("provider-callback-error")).toHaveTextContent("The authorization was cancelled on Bitbucket Cloud. Nothing was changed.");
    unmount();
    render(<ProviderCallback provider="gitlab" code="c" />);
    expect(screen.getByTestId("provider-callback-error")).toHaveTextContent("The response from GitLab was incomplete.");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("explains a refused state or an account linked elsewhere", async () => {
    fetchMock.mockResolvedValue(apiErrorResponse(409, "PROVIDER_ACCOUNT_IN_USE"));
    render(<ProviderCallback provider="gitlab" code="c" state={STATE} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("already linked to another CodeDNA user");
  });
});
