import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { RegisterForm } from "@/components/auth/register-form";
import { apiErrorResponse, jsonResponse, user } from "@/test/responses";
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

async function fill(values: { name?: string; email?: string; password?: string; confirmation?: string }) {
  const ui = userEvent.setup();
  if (values.name) await ui.type(screen.getByLabelText("Name"), values.name);
  if (values.email) await ui.type(screen.getByLabelText("Email"), values.email);
  if (values.password) await ui.type(screen.getByLabelText("Password"), values.password);
  if (values.confirmation) await ui.type(screen.getByLabelText("Confirm password"), values.confirmation);
  await ui.click(screen.getByRole("button", { name: "Create account" }));
}

describe("RegisterForm", () => {
  it("checks required fields, password length and confirmation before calling the API", async () => {
    render(<RegisterForm />);

    await fill({ email: "ada@example.com", password: "short", confirmation: "different" });

    expect(screen.getByText("Enter your name.")).toBeInTheDocument();
    expect(screen.getByText("Use at least 8 characters.")).toBeInTheDocument();
    expect(screen.getByText("The passwords do not match.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("registers, sending the exact backend fields, and navigates to the app", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }, 201));
    render(<RegisterForm />);

    await fill({ name: " Ada Lovelace ", email: "ada@example.com", password: "secret-123", confirmation: "secret-123" });

    const body = JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body));
    expect(body).toEqual({
      name: "Ada Lovelace",
      email: "ada@example.com",
      password: "secret-123",
      password_confirmation: "secret-123",
    });
    expect(router.replace).toHaveBeenCalledWith("/app");
  });

  it("shows server validation errors (e.g. duplicate email) on the right field", async () => {
    fetchMock.mockResolvedValueOnce(
      apiErrorResponse(422, "VALIDATION_FAILED", { fields: { email: ["The email has already been taken."] } }),
    );
    render(<RegisterForm />);

    await fill({ name: "Ada", email: "ada@example.com", password: "secret-123", confirmation: "secret-123" });

    expect(await screen.findByText("The email has already been taken.")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("alert")).toHaveTextContent("Please correct the highlighted fields.");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("explains that the installation does not accept new accounts", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(403, "REGISTRATION_CLOSED"));
    render(<RegisterForm />);
    await fill({ name: "Ada", email: "ada@example.com", password: "secret-123", confirmation: "secret-123" });

    expect(await screen.findByRole("alert")).toHaveTextContent("New accounts cannot be created on this installation");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("explains a server failure with its reference and a network failure, and stays on the form", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR"));
    render(<RegisterForm />);
    await fill({ name: "Ada", email: "ada@example.com", password: "secret-123", confirmation: "secret-123" });

    expect(await screen.findByRole("alert")).toHaveTextContent("11111111-2222-4333-8444-555555555555");
    expect(screen.getByRole("alert")).not.toHaveTextContent("server message");

    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    await userEvent.setup().click(screen.getByRole("button", { name: "Create account" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Unable to connect to CodeDNA");
    expect(router.replace).not.toHaveBeenCalled();
  });
});
