import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { upload } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { jsonResponse } from "@/test/responses";
import { FakeXhr } from "@/test/xhr";

const fetchMock = vi.fn<typeof fetch>();
/** An opaque per-upload identifier, as the UI generates with crypto.randomUUID(). */
const UPLOAD_ATTEMPT = "attempt-12345678";

beforeEach(() => {
  FakeXhr.reset();
  vi.stubGlobal("XMLHttpRequest", FakeXhr);
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=token%3D; path=/";
});

afterEach(() => FakeXhr.reset());

describe("upload", () => {
  it("POSTs the form with cookies, the CSRF header and extra headers, and reports progress", async () => {
    const progress: number[] = [];
    FakeXhr.handler = (xhr) => {
      xhr.progress(50, 100);
      xhr.progress(100, 100);
      xhr.respond(201, { data: { id: "x" } });
    };
    const form = new FormData();

    await expect(
      upload("/api/v1/projects/p/source-snapshots", form, {
        headers: { "Idempotency-Key": UPLOAD_ATTEMPT },
        onProgress: (fraction) => progress.push(fraction),
      }),
    ).resolves.toEqual({ data: { id: "x" } });

    const [xhr] = FakeXhr.instances;
    expect(xhr?.method).toBe("POST");
    expect(xhr?.url).toBe("/api/v1/projects/p/source-snapshots");
    expect(xhr?.withCredentials).toBe(true);
    expect(xhr?.body).toBe(form);
    expect(xhr?.requestHeaders).toMatchObject({
      Accept: "application/json",
      "X-XSRF-TOKEN": "token=",
      "Idempotency-Key": UPLOAD_ATTEMPT,
    });
    expect(xhr?.requestHeaders).not.toHaveProperty("Content-Type");
    expect(progress).toEqual([0.5, 1]);
  });

  it("parses API errors like every other request", async () => {
    FakeXhr.handler = (xhr) =>
      xhr.respond(422, { error: { code: "SOURCE_ARCHIVE_UNSAFE", message: "x", request_id: "rid" } }, { "x-request-id": "rid" });

    await expect(upload("/api/v1/x", new FormData())).rejects.toMatchObject({ status: 422, code: "SOURCE_ARCHIVE_UNSAFE", requestId: "rid" });
  });

  it("refreshes the CSRF cookie and retries once on 419", async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
    let calls = 0;
    FakeXhr.handler = (xhr) => {
      calls++;
      if (calls === 1) xhr.respond(419, { error: { code: "CSRF_TOKEN_MISMATCH", message: "x", request_id: null } });
      else xhr.respond(201, { data: { ok: true } });
    };

    await expect(upload("/api/v1/x", new FormData())).resolves.toEqual({ data: { ok: true } });
    expect(FakeXhr.instances).toHaveLength(2);
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe("/sanctum/csrf-cookie");
  });

  it("fetches the CSRF cookie first when it is missing", async () => {
    document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
    fetchMock.mockImplementationOnce(async () => {
      document.cookie = "XSRF-TOKEN=fresh; path=/";
      return new Response(null, { status: 204 });
    });
    FakeXhr.handler = (xhr) => xhr.respond(201, { data: {} });

    await upload("/api/v1/x", new FormData());

    expect(FakeXhr.instances[0]?.requestHeaders["X-XSRF-TOKEN"]).toBe("fresh");
  });

  it("turns a network failure into NETWORK_ERROR", async () => {
    FakeXhr.handler = (xhr) => xhr.failNetwork();

    const error = await upload("/api/v1/x", new FormData()).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: null, code: "NETWORK_ERROR" });
  });

  it("only accepts same-origin paths", async () => {
    await expect(upload("https://evil.example/x", new FormData())).rejects.toThrow("same-origin");
    expect(FakeXhr.instances).toHaveLength(0);
    expect(fetchMock).not.toHaveBeenCalled();
    void jsonResponse;
  });
});
