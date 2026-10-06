import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProfileSettings } from "@/components/profile/profile-settings";
import { apiErrorResponse, jsonResponse, profile } from "@/test/responses";
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

describe("ProfileSettings", () => {
  it("shows a loading state, then the form with the loaded profile", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { ...profile, company: "Example Corp" } }));
    render(<ProfileSettings />);

    expect(screen.getByRole("status")).toHaveTextContent("Loading your profile…");
    expect(await screen.findByLabelText("Company")).toHaveValue("Example Corp");
  });

  it("shows an error with a retry", async () => {
    fetchMock
      .mockResolvedValueOnce(apiErrorResponse(503, "SERVICE_UNAVAILABLE"))
      .mockResolvedValueOnce(jsonResponse({ data: profile }));
    render(<ProfileSettings />);

    expect(await screen.findByRole("alert")).toHaveTextContent("CodeDNA is having trouble right now");
    await userEvent.setup().click(screen.getByRole("button", { name: "Try again" }));

    expect(await screen.findByLabelText("Display name")).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("sends the user to sign in when the session has ended", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<ProfileSettings />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
