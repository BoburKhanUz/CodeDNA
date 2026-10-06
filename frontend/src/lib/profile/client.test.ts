import { beforeEach, describe, expect, it, vi } from "vitest";

import { changePassword, getProfile, updateProfile } from "@/lib/profile/client";
import { apiErrorResponse, jsonResponse, noContent, profile } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token; path=/";
});

describe("profile client", () => {
  it("loads the profile from the data envelope", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: profile }));

    await expect(getProfile()).resolves.toEqual(profile);
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/api/v1/profile");
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("GET");
  });

  it("updates with PATCH, the CSRF header and a JSON body", async () => {
    const updated = { ...profile, city: "Tashkent" };
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: updated }));

    await expect(updateProfile({ city: "Tashkent" })).resolves.toEqual(updated);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toBe("/api/v1/profile");
    expect(init?.method).toBe("PATCH");
    expect((init?.headers as Record<string, string>)["X-XSRF-TOKEN"]).toBe("token");
    expect(JSON.parse(String(init?.body))).toEqual({ city: "Tashkent" });
  });

  it("changes the password (204)", async () => {
    fetchMock.mockResolvedValueOnce(noContent());

    await expect(
      changePassword({ current_password: "old-secret-1", password: "new-secret-1", password_confirmation: "new-secret-1" }),
    ).resolves.toBeUndefined();
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/api/v1/auth/password");
    expect(fetchMock.mock.calls[0]?.[1]?.method).toBe("PATCH");
  });

  it("surfaces validation errors as ApiError with field errors", async () => {
    fetchMock.mockResolvedValueOnce(
      apiErrorResponse(422, "VALIDATION_FAILED", { fields: { timezone: ["The timezone must be a valid IANA time zone."] } }),
    );

    await expect(updateProfile({ timezone: "UTC+5" })).rejects.toMatchObject({
      code: "VALIDATION_FAILED",
      fieldErrors: { timezone: ["The timezone must be a valid IANA time zone."] },
    });
  });
});
