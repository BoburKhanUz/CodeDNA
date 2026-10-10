import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useConfiguredProviders } from "@/lib/projects/use-configured-providers";
import { apiErrorResponse, jsonResponse } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

function respond(github: Response, providers: Response) {
  fetchMock.mockImplementation(async (input) => (String(input).includes("/api/v1/github") ? github : providers).clone());
}

const accounts = (gitlab: boolean, bitbucket: boolean) => ({
  data: [
    { provider: "gitlab", name: "GitLab", configured: gitlab, host: null, account: null },
    { provider: "bitbucket", name: "Bitbucket Cloud", configured: bitbucket, host: null, account: null },
  ],
});

describe("useConfiguredProviders", () => {
  it("reports what the server has configured", async () => {
    respond(jsonResponse({ data: { configured: true, account: null } }), jsonResponse(accounts(false, true)));
    const { result } = renderHook(() => useConfiguredProviders());
    await waitFor(() => expect(result.current).toEqual({ github: true, other: true }));
  });

  it("reports nothing configured for an uploads-only server (V1.0.0)", async () => {
    respond(jsonResponse({ data: { configured: false, account: null } }), jsonResponse(accounts(false, false)));
    const { result } = renderHook(() => useConfiguredProviders());
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(result.current).toEqual({ github: false, other: false });
  });

  it("fails closed when the status cannot be read or is unexpected", async () => {
    respond(apiErrorResponse(500, "INTERNAL_ERROR"), jsonResponse({ data: { configured: true } }));
    const { result } = renderHook(() => useConfiguredProviders());
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(result.current).toEqual({ github: false, other: false });
  });

  it("requests nothing while disabled", () => {
    const { result } = renderHook(() => useConfiguredProviders(false));
    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current).toEqual({ github: false, other: false });
  });
});
