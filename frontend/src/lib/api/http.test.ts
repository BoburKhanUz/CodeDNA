import { describe, expect, it } from "vitest";

import { ApiError, describeApiError } from "@/lib/api/errors";
import { parseApiResponse, parseRetryAfter } from "@/lib/api/http";
import { apiErrorResponse, jsonResponse, noContent, user } from "@/test/responses";

async function caught(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (error) {
    if (error instanceof ApiError) return error;
    throw error;
  }
  throw new Error("expected an ApiError");
}

describe("parseApiResponse", () => {
  it("returns the JSON body of a successful response", async () => {
    await expect(parseApiResponse(jsonResponse({ data: user }))).resolves.toEqual({ data: user });
  });

  it("returns undefined for 204 No Content", async () => {
    await expect(parseApiResponse(noContent())).resolves.toBeUndefined();
  });

  it("maps the Laravel error envelope, including validation fields and request ID", async () => {
    const error = await caught(
      parseApiResponse(
        apiErrorResponse(422, "VALIDATION_FAILED", {
          requestId: "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
          fields: { email: ["The email has already been taken."] },
        }),
      ),
    );

    expect(error.status).toBe(422);
    expect(error.code).toBe("VALIDATION_FAILED");
    expect(error.requestId).toBe("aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee");
    expect(error.fieldErrors).toEqual({ email: ["The email has already been taken."] });
  });

  it("reads Retry-After for rate limiting", async () => {
    const error = await caught(parseApiResponse(apiErrorResponse(429, "RATE_LIMITED", { headers: { "retry-after": "42" } })));

    expect(error.code).toBe("RATE_LIMITED");
    expect(error.retryAfterSeconds).toBe(42);
  });

  it("treats non-envelope errors (e.g. an HTML 502 from a proxy) as unexpected", async () => {
    const response = new Response("<html>Bad Gateway</html>", {
      status: 502,
      headers: { "content-type": "text/html", "x-request-id": "rid" },
    });
    const error = await caught(parseApiResponse(response));

    expect(error.status).toBe(502);
    expect(error.code).toBe("UNEXPECTED_RESPONSE");
    expect(error.requestId).toBe("rid");
  });

  it("keeps every code the backend sends, such as ANALYSIS_NOT_COMPLETED (scripts/check_contracts.py checks the full list)", async () => {
    const error = await caught(parseApiResponse(apiErrorResponse(409, "ANALYSIS_NOT_COMPLETED")));

    expect(error.code).toBe("ANALYSIS_NOT_COMPLETED");
    expect(describeApiError(error)).toBe("This analysis has no result yet: it has not succeeded.");
  });

  it("ignores unknown error codes and malformed details", async () => {
    const error = await caught(parseApiResponse(jsonResponse({ error: { code: "SOMETHING_NEW", details: 5 } }, 400)));

    expect(error.code).toBe("UNEXPECTED_RESPONSE");
    expect(error.fieldErrors).toEqual({});
  });

  it("rejects a successful response without a JSON body", async () => {
    const error = await caught(parseApiResponse(new Response("ok", { status: 200, headers: { "content-type": "text/plain" } })));

    expect(error.code).toBe("UNEXPECTED_RESPONSE");
  });
});

describe("parseRetryAfter", () => {
  it.each([
    ["30", 30],
    [" 5 ", 5],
    [null, null],
    ["Wed, 21 Oct 2026 07:28:00 GMT", null],
    ["-1", null],
  ])("parses %j as %j", (value, expected) => {
    expect(parseRetryAfter(value)).toBe(expected);
  });
});
