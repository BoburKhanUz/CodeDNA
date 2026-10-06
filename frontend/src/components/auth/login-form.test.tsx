import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { LoginForm } from "@/components/auth/login-form";
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

async function fillAndSubmit(email: string, password: string) {
  const ui = userEvent.setup();
  if (email) await ui.type(screen.getByLabelText("Email"), email);
  if (password) await ui.type(screen.getByLabelText("Password"), password);
  await ui.click(screen.getByRole("button", { name: "Sign in" }));
}

describe("LoginForm", () => {
  it("shows a fixed notice after a password change", () => {
    render(<LoginForm notice="password-changed" />);

    expect(screen.getByRole("status")).toHaveTextContent("Your password was changed. Sign in with your new password.");
  });

  it("renders an accessible form", () => {
    render(<LoginForm />);

    expect(screen.getByRole("heading", { name: "Sign in" })).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toHaveAttribute("type", "email");
    expect(screen.getByLabelText("Email")).toHaveAttribute("autocomplete", "email");
    expect(screen.getByLabelText("Password")).toHaveAttribute("type", "password");
    expect(screen.getByRole("link", { name: "Create an account" })).toHaveAttribute("href", "/register");
  });

  it("validates input on the client before calling the API", async () => {
    render(<LoginForm />);

    await fillAndSubmit("not-an-email", "");

    expect(screen.getByText("Enter a valid email address.")).toBeInTheDocument();
    expect(screen.getByText("Enter your password.")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("Email")).toHaveAccessibleDescription("Enter a valid email address.");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("signs in and navigates to the app", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }));
    render(<LoginForm />);

    await fillAndSubmit("ada@example.com", "secret-123");

    expect(router.replace).toHaveBeenCalledWith("/app");
    expect(router.refresh).toHaveBeenCalled();
  });

  it("shows a generic message for invalid credentials and stays on the page", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(422, "INVALID_CREDENTIALS"));
    render(<LoginForm />);

    await fillAndSubmit("ada@example.com", "wrong-password");

    expect(await screen.findByRole("alert")).toHaveTextContent("The email or password is incorrect.");
    expect(screen.queryByText("server message")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sign in" })).toBeEnabled();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("shows server validation errors next to the fields", async () => {
    fetchMock.mockResolvedValueOnce(
      apiErrorResponse(422, "VALIDATION_FAILED", { fields: { email: ["The email field must be a valid email address."] } }),
    );
    render(<LoginForm />);

    await fillAndSubmit("ada@example.com", "secret-123");

    expect(await screen.findByText("The email field must be a valid email address.")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
  });

  it("shows the rate-limit wait time", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(429, "RATE_LIMITED", { headers: { "retry-after": "37" } }));
    render(<LoginForm />);

    await fillAndSubmit("ada@example.com", "secret-123");

    expect(await screen.findByRole("alert")).toHaveTextContent("Too many attempts. Try again in 37 seconds.");
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("shows a safe message with a reference for server errors", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR", { requestId: "req-500" }));
    render(<LoginForm />);

    await fillAndSubmit("ada@example.com", "secret-123");

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("CodeDNA is having trouble right now.");
    expect(alert).toHaveTextContent("Reference: req-500");
  });

  it("reports network failures as a connection problem", async () => {
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    render(<LoginForm />);

    await fillAndSubmit("ada@example.com", "secret-123");

    expect(await screen.findByRole("alert")).toHaveTextContent("Unable to connect to CodeDNA.");
  });
});
