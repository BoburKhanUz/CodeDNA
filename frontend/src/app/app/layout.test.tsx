import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Session } from "@/lib/auth/session";
import { user } from "@/test/responses";

const getSession = vi.fn<() => Promise<Session>>();
vi.mock("@/lib/auth/session", () => ({ getSession: () => getSession() }));

const redirect = vi.fn((path: string) => {
  throw new Error(`NEXT_REDIRECT:${path}`);
});
vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { redirect: (path: string) => redirect(path), useRouter: () => router, usePathname: () => "/app" };
});

const { default: AuthenticatedLayout } = await import("@/app/app/layout");
const { default: AuthLayout } = await import("@/app/(auth)/layout");

beforeEach(() => {
  getSession.mockReset();
  redirect.mockClear();
});

describe("/app (authenticated area)", () => {
  it("redirects unauthenticated visitors to /login without rendering the app", async () => {
    getSession.mockResolvedValueOnce({ status: "unauthenticated" });

    await expect(AuthenticatedLayout({ children: <p>secret app content</p> })).rejects.toThrow("NEXT_REDIRECT:/login");
    expect(redirect).toHaveBeenCalledWith("/login");
  });

  it("renders the shell with the signed-in user", async () => {
    getSession.mockResolvedValueOnce({ status: "authenticated", user });

    render(await AuthenticatedLayout({ children: <p>app content</p> }));

    expect(screen.getByText("app content")).toBeInTheDocument();
    expect(screen.getByText(user.name)).toBeInTheDocument();
    expect(screen.getByText(user.email)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sign out" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Home" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "Profile" })).toHaveAttribute("href", "/app/profile");
    expect(screen.getByRole("link", { name: "Profile" })).not.toHaveAttribute("aria-current");
    expect(screen.getByRole("link", { name: "Projects" })).toHaveAttribute("href", "/app/projects");
    expect(screen.getByRole("link", { name: "Billing" })).toHaveAttribute("href", "/app/billing");
    expect(screen.getByRole("link", { name: "Teams" })).toHaveAttribute("href", "/app/organizations");
  });

  it("propagates API failures to the error boundary instead of logging the user out", async () => {
    getSession.mockRejectedValueOnce(new Error("NETWORK_ERROR"));

    await expect(AuthenticatedLayout({ children: null })).rejects.toThrow("NETWORK_ERROR");
    expect(redirect).not.toHaveBeenCalled();
  });
});

describe("/login and /register", () => {
  it("send signed-in visitors to /app", async () => {
    getSession.mockResolvedValueOnce({ status: "authenticated", user });

    await expect(AuthLayout({ children: null })).rejects.toThrow("NEXT_REDIRECT:/app");
  });

  it("render for signed-out visitors", async () => {
    getSession.mockResolvedValueOnce({ status: "unauthenticated" });

    render(await AuthLayout({ children: <p>login form</p> }));

    expect(screen.getByText("login form")).toBeInTheDocument();
  });
});
