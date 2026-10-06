import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PasswordForm } from "@/components/profile/password-form";
import { apiErrorResponse, noContent } from "@/test/responses";
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

async function fill(values: { current?: string; password?: string; confirmation?: string }) {
  const ui = userEvent.setup();
  if (values.current) await ui.type(screen.getByLabelText("Current password"), values.current);
  if (values.password) await ui.type(screen.getByLabelText("New password"), values.password);
  if (values.confirmation) await ui.type(screen.getByLabelText("Confirm new password"), values.confirmation);
  await ui.click(screen.getByRole("button", { name: "Change password" }));
}

describe("PasswordForm", () => {
  it("validates before calling the API", async () => {
    render(<PasswordForm />);

    await fill({ password: "short", confirmation: "different" });

    expect(screen.getByText("Enter your current password.")).toBeInTheDocument();
    expect(screen.getByText("Use at least 8 characters.")).toBeInTheDocument();
    expect(screen.getByText("The passwords do not match.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("rejects reusing the current password", async () => {
    render(<PasswordForm />);

    await fill({ current: "same-secret-1", password: "same-secret-1", confirmation: "same-secret-1" });

    expect(screen.getByText("The new password must be different from the current password.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("changes the password and sends the user to sign in again", async () => {
    fetchMock.mockResolvedValueOnce(noContent());
    render(<PasswordForm />);

    await fill({ current: "old-secret-1", password: "new-secret-1", confirmation: "new-secret-1" });

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toBe("/api/v1/auth/password");
    expect(init?.method).toBe("PATCH");
    expect(JSON.parse(String(init?.body))).toEqual({
      current_password: "old-secret-1",
      password: "new-secret-1",
      password_confirmation: "new-secret-1",
    });
    expect(router.replace).toHaveBeenCalledWith("/login?reason=password-changed");
  });

  it("shows a wrong current password on its field and clears the inputs", async () => {
    fetchMock.mockResolvedValueOnce(
      apiErrorResponse(422, "VALIDATION_FAILED", { fields: { current_password: ["The current password is incorrect."] } }),
    );
    render(<PasswordForm />);

    await fill({ current: "wrong-secret-1", password: "new-secret-1", confirmation: "new-secret-1" });

    expect(await screen.findByText("The current password is incorrect.")).toBeInTheDocument();
    expect(screen.getByLabelText("Current password")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("Current password")).toHaveValue("");
    expect(screen.getByLabelText("New password")).toHaveValue("");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("explains rate limiting", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(429, "RATE_LIMITED", { headers: { "Retry-After": "42" } }));
    render(<PasswordForm />);

    await fill({ current: "old-secret-1", password: "new-secret-1", confirmation: "new-secret-1" });

    expect(await screen.findByRole("alert")).toHaveTextContent("Too many attempts. Try again in 42 seconds.");
  });
});
