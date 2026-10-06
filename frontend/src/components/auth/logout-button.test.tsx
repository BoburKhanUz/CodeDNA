import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { LogoutButton } from "@/components/auth/logout-button";
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

describe("LogoutButton", () => {
  it("logs out and returns to the login page", async () => {
    fetchMock.mockResolvedValueOnce(noContent());
    render(<LogoutButton />);

    await userEvent.click(screen.getByRole("button", { name: "Sign out" }));

    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/api/v1/auth/logout");
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("POST");
    expect(router.replace).toHaveBeenCalledWith("/login");
    expect(router.refresh).toHaveBeenCalled();
  });

  it("still returns to the login page when the session had already expired", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<LogoutButton />);

    await userEvent.click(screen.getByRole("button", { name: "Sign out" }));

    expect(router.replace).toHaveBeenCalledWith("/login");
  });

  it("stays and explains when the API cannot be reached", async () => {
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    render(<LogoutButton />);

    await userEvent.click(screen.getByRole("button", { name: "Sign out" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Unable to connect to CodeDNA.");
    expect(router.replace).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Sign out" })).toBeEnabled();
  });
});
