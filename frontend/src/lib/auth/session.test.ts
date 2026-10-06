import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiErrorResponse, jsonResponse, user } from "@/test/responses";

const incomingHeaders = new Headers();
vi.mock("next/headers", () => ({ headers: async () => incomingHeaders }));
// React's `cache` dedupes per server request; in tests every call should run.
vi.mock("react", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react")>()),
  cache: <T,>(fn: T) => fn,
}));

const { getSession } = await import("@/lib/auth/session");

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  vi.stubEnv("BACKEND_INTERNAL_URL", "http://nginx");
  vi.stubEnv("FRONTEND_URL", "http://localhost");
  for (const key of [...incomingHeaders.keys()]) incomingHeaders.delete(key);
});

describe("getSession (server)", () => {
  it("treats a visitor without cookies as unauthenticated without calling the API", async () => {
    await expect(getSession()).resolves.toEqual({ status: "unauthenticated" });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("forwards the browser's cookies to GET /api/v1/me as a first-party request", async () => {
    incomingHeaders.set("cookie", "codedna-session=abc; XSRF-TOKEN=def");
    incomingHeaders.set("x-forwarded-for", "203.0.113.7");
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }));

    await expect(getSession()).resolves.toEqual({ status: "authenticated", user });

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe("http://nginx/api/v1/me");
    expect(init?.cache).toBe("no-store");
    expect(init?.headers).toMatchObject({
      Cookie: "codedna-session=abc; XSRF-TOKEN=def",
      Origin: "http://localhost",
      Accept: "application/json",
      "X-Forwarded-For": "203.0.113.7",
    });
  });

  it("returns unauthenticated for 401", async () => {
    incomingHeaders.set("cookie", "codedna-session=expired");
    fetchMock.mockResolvedValueOnce(apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));

    await expect(getSession()).resolves.toEqual({ status: "unauthenticated" });
  });

  it("throws on server errors instead of pretending the user is logged out", async () => {
    incomingHeaders.set("cookie", "codedna-session=abc");
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR"));

    await expect(getSession()).rejects.toMatchObject({ status: 500, code: "INTERNAL_ERROR" });
  });

  it("throws NETWORK_ERROR when the API is unreachable", async () => {
    incomingHeaders.set("cookie", "codedna-session=abc");
    fetchMock.mockRejectedValueOnce(new TypeError("fetch failed"));

    await expect(getSession()).rejects.toMatchObject({ code: "NETWORK_ERROR" });
  });

  it("fails clearly when server configuration is missing", async () => {
    incomingHeaders.set("cookie", "codedna-session=abc");
    vi.stubEnv("BACKEND_INTERNAL_URL", "");

    await expect(getSession()).rejects.toThrow("BACKEND_INTERNAL_URL is not set");
  });
});
