import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { apiErrorResponse, jsonResponse, noContent, user } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

function call(index: number): { url: string; init: RequestInit; headers: Record<string, string> } {
  const [url, init] = fetchMock.mock.calls[index] ?? [];
  return { url: String(url), init: init ?? {}, headers: (init?.headers ?? {}) as Record<string, string> };
}

/** Simulates Laravel setting the XSRF-TOKEN cookie (URL-encoded, as Laravel does). */
function setXsrfCookie(value: string) {
  document.cookie = `XSRF-TOKEN=${encodeURIComponent(value)}; path=/`;
}

describe("api client", () => {
  it("sends same-origin GET requests with credentials and JSON headers", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }));

    await expect(api.get("/api/v1/me")).resolves.toEqual({ data: user });

    const { url, init, headers } = call(0);
    expect(url).toBe("/api/v1/me");
    expect(init.method).toBe("GET");
    expect(init.credentials).toBe("include");
    expect(headers.Accept).toBe("application/json");
    expect(headers["X-XSRF-TOKEN"]).toBeUndefined();
  });

  it("fetches the CSRF cookie before the first state-changing request and sends the decoded token", async () => {
    fetchMock.mockImplementationOnce(async () => {
      setXsrfCookie("token+with/special=chars");
      return noContent();
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: user }));

    await api.post("/api/v1/auth/login", { email: "ada@example.com", password: "secret-123" });

    expect(call(0).url).toBe("/sanctum/csrf-cookie");
    expect(call(0).init.credentials).toBe("include");
    const login = call(1);
    expect(login.url).toBe("/api/v1/auth/login");
    expect(login.headers["X-XSRF-TOKEN"]).toBe("token+with/special=chars");
    expect(login.headers["Content-Type"]).toBe("application/json");
    expect(JSON.parse(String(login.init.body))).toEqual({ email: "ada@example.com", password: "secret-123" });
  });

  it("does not refetch the CSRF cookie when it is already present", async () => {
    setXsrfCookie("existing");
    fetchMock.mockResolvedValueOnce(noContent());

    await api.post("/api/v1/auth/logout");

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(call(0).headers["X-XSRF-TOKEN"]).toBe("existing");
  });

  it("refreshes the CSRF cookie and retries exactly once on 419", async () => {
    setXsrfCookie("stale");
    fetchMock
      .mockResolvedValueOnce(apiErrorResponse(419, "CSRF_TOKEN_MISMATCH"))
      .mockImplementationOnce(async () => {
        setXsrfCookie("fresh");
        return noContent();
      })
      .mockResolvedValueOnce(jsonResponse({ data: user }));

    await expect(api.post("/api/v1/auth/login", {})).resolves.toEqual({ data: user });

    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(call(1).url).toBe("/sanctum/csrf-cookie");
    expect(call(2).headers["X-XSRF-TOKEN"]).toBe("fresh");
  });

  it("gives up after a second 419", async () => {
    setXsrfCookie("stale");
    fetchMock
      .mockResolvedValueOnce(apiErrorResponse(419, "CSRF_TOKEN_MISMATCH"))
      .mockResolvedValueOnce(noContent())
      .mockResolvedValueOnce(apiErrorResponse(419, "CSRF_TOKEN_MISMATCH"));

    await expect(api.post("/api/v1/auth/login", {})).rejects.toMatchObject({ code: "CSRF_TOKEN_MISMATCH" });
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it("does not retry rate-limited requests", async () => {
    setXsrfCookie("token");
    fetchMock.mockResolvedValueOnce(apiErrorResponse(429, "RATE_LIMITED", { headers: { "retry-after": "60" } }));

    await expect(api.post("/api/v1/auth/login", {})).rejects.toMatchObject({ status: 429, retryAfterSeconds: 60 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("reports network failures as NETWORK_ERROR, not as an authentication state", async () => {
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));

    const error = await api.get("/api/v1/me").catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: null, code: "NETWORK_ERROR" });
  });

  it.each(["https://evil.example/api/v1/me", "//evil.example/api", "api/v1/me", "/\\evil.example"])(
    "refuses non-same-origin path %s",
    async (path) => {
      await expect(api.get(path)).rejects.toThrow(/same-origin/);
      expect(fetchMock).not.toHaveBeenCalled();
    },
  );
});
