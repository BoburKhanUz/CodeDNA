import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useChallengesEnabled } from "@/lib/challenge/use-challenges-enabled";
import { apiErrorResponse, jsonResponse } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

const respond = (response: Response) => fetchMock.mockImplementation(async () => response.clone());

describe("useChallengesEnabled", () => {
  it("reports challenges the server has enabled", async () => {
    respond(jsonResponse({ data: { enabled: true, evaluation_available: false } }));
    const { result } = renderHook(() => useChallengesEnabled());
    await waitFor(() => expect(result.current).toBe(true));
    expect(String(fetchMock.mock.calls[0]?.[0])).toContain("/api/v1/challenges/status");
  });

  it("reports challenges off (V1.0.0)", async () => {
    respond(jsonResponse({ data: { enabled: false, evaluation_available: false } }));
    const { result } = renderHook(() => useChallengesEnabled());
    await waitFor(() => expect(result.current).toBe(false));
  });

  it("is unknown (null) until the server answers", () => {
    fetchMock.mockReturnValue(new Promise(() => {}));
    const { result } = renderHook(() => useChallengesEnabled());
    expect(result.current).toBeNull();
  });

  it.each([
    ["an error", () => apiErrorResponse(500, "INTERNAL_ERROR")],
    ["an unexpected answer", () => jsonResponse({ data: { enabled: "yes" } })],
  ])("fails closed on %s", async (_, response) => {
    respond(response());
    const { result } = renderHook(() => useChallengesEnabled());
    await waitFor(() => expect(result.current).toBe(false));
  });

  it("requests nothing while disabled", () => {
    const { result } = renderHook(() => useChallengesEnabled(false));
    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current).toBeNull();
  });
});
