import { beforeEach, describe, expect, it, vi } from "vitest";

import { login, logout, register } from "@/lib/auth/client";
import { apiErrorResponse, jsonResponse, noContent, user } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token; path=/";
});

describe("auth client", () => {
  it("logs in and returns the user from the data envelope", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }));

    await expect(login({ email: "ada@example.com", password: "secret-123" })).resolves.toEqual(user);
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/api/v1/auth/login");
  });

  it("registers and returns the created user", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }, 201));

    await expect(
      register({ name: "Ada", email: "ada@example.com", password: "secret-123", password_confirmation: "secret-123" }),
    ).resolves.toEqual(user);
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/api/v1/auth/register");
  });

  it("logs out with 204", async () => {
    fetchMock.mockResolvedValueOnce(noContent());

    await expect(logout()).resolves.toBeUndefined();
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/api/v1/auth/logout");
  });

  it("treats an already-expired session (401) as logged out", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));

    await expect(logout()).resolves.toBeUndefined();
  });

  it("reports other logout failures", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR"));

    await expect(logout()).rejects.toMatchObject({ code: "INTERNAL_ERROR" });
  });
});
