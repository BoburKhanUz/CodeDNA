import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProfileForm } from "@/components/profile/profile-form";
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
  document.cookie = "XSRF-TOKEN=token; path=/";
});

function sentBody(): Record<string, unknown> {
  return JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body));
}

describe("ProfileForm", () => {
  it("shows the current profile values", () => {
    render(
      <ProfileForm
        profile={{ ...profile, display_name: "Ada", timezone: "Asia/Tashkent", locale: "uz", preferred_language: "php" }}
      />,
    );

    expect(screen.getByLabelText("Display name")).toHaveValue("Ada");
    expect(screen.getByLabelText("Time zone")).toHaveValue("Asia/Tashkent");
    expect(screen.getByLabelText("Interface language")).toHaveValue("uz");
    expect(screen.getByLabelText("Preferred programming language")).toHaveValue("php");
  });

  it("saves every field, sending empty optional fields as null, and stays on the page", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { ...profile, city: "Tashkent", country_code: "UZ" } }));
    render(<ProfileForm profile={profile} />);

    await ui.type(screen.getByLabelText("City"), " Tashkent ");
    await ui.type(screen.getByLabelText("Country code"), "uz");
    await ui.type(screen.getByLabelText("GitHub username"), "@octocat");
    await ui.click(screen.getByRole("button", { name: "Save profile" }));

    expect(sentBody()).toEqual({
      timezone: "UTC",
      locale: "en",
      display_name: null,
      job_title: null,
      company: null,
      bio: null,
      city: "Tashkent",
      country_code: "UZ",
      website_url: null,
      github_username: "octocat",
      linkedin_url: null,
      avatar_url: null,
      preferred_language: null,
    });
    expect(await screen.findByText("Profile saved.")).toBeInTheDocument();
    expect(screen.getByLabelText("City")).toHaveValue("Tashkent");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("validates fields before calling the API", async () => {
    const ui = userEvent.setup();
    render(<ProfileForm profile={profile} />);

    await ui.type(screen.getByLabelText("Website"), "javascript:alert(1)");
    await ui.type(screen.getByLabelText("GitHub username"), "-bad-");
    await ui.clear(screen.getByLabelText("Time zone"));
    await ui.click(screen.getByRole("button", { name: "Save profile" }));

    expect(screen.getByText("Enter a full URL starting with https://.")).toBeInTheDocument();
    expect(screen.getByLabelText("Website")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("GitHub username")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByText("Choose a time zone.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows server validation errors on the right field", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(
      apiErrorResponse(422, "VALIDATION_FAILED", {
        fields: { timezone: ["The timezone must be a valid IANA time zone, such as Asia/Tashkent."] },
      }),
    );
    render(<ProfileForm profile={profile} />);

    await ui.clear(screen.getByLabelText("Time zone"));
    await ui.type(screen.getByLabelText("Time zone"), "UTC+5");
    await ui.click(screen.getByRole("button", { name: "Save profile" }));

    expect(await screen.findByText("The timezone must be a valid IANA time zone, such as Asia/Tashkent.")).toBeInTheDocument();
    expect(screen.getByLabelText("Time zone")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("alert")).toHaveTextContent("Please correct the highlighted fields.");
    expect(screen.queryByText("Profile saved.")).not.toBeInTheDocument();
  });

  it("shows a safe message with a reference for server errors", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR", { requestId: "req-123" }));
    render(<ProfileForm profile={profile} />);

    await ui.click(screen.getByRole("button", { name: "Save profile" }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("CodeDNA is having trouble right now");
    expect(alert).toHaveTextContent("req-123");
    expect(alert).not.toHaveTextContent("server message");
  });

  it("sends the user to sign in when the session has ended", async () => {
    const ui = userEvent.setup();
    fetchMock.mockResolvedValueOnce(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<ProfileForm profile={profile} />);

    await ui.click(screen.getByRole("button", { name: "Save profile" }));

    expect(router.replace).toHaveBeenCalledWith("/login");
  });
});
