import { describe, expect, it } from "vitest";

import { ApiError, describeApiError, shouldShowReference } from "@/lib/api/errors";

const error = (init: ConstructorParameters<typeof ApiError>[0]) => new ApiError(init);

describe("describeApiError", () => {
  it.each([
    [error({ status: null, code: "NETWORK_ERROR" }), "Unable to connect to CodeDNA. Check your connection and try again."],
    [error({ status: 401, code: "AUTHENTICATION_REQUIRED" }), "Your session has ended. Please sign in again."],
    [error({ status: 403, code: "FORBIDDEN" }), "You don't have permission to do that."],
    [error({ status: 419, code: "CSRF_TOKEN_MISMATCH" }), "Your session expired. Please try again."],
    [error({ status: 422, code: "VALIDATION_FAILED" }), "Please correct the highlighted fields."],
    [error({ status: 422, code: "INVALID_CREDENTIALS" }), "The email or password is incorrect."],
    [error({ status: 429, code: "RATE_LIMITED", retryAfterSeconds: 1 }), "Too many attempts. Try again in 1 second."],
    [error({ status: 429, code: "RATE_LIMITED", retryAfterSeconds: 42 }), "Too many attempts. Try again in 42 seconds."],
    [error({ status: 429, code: "RATE_LIMITED", retryAfterSeconds: 90 }), "Too many attempts. Try again in 2 minutes."],
    [error({ status: 429, code: "RATE_LIMITED" }), "Too many attempts. Please wait a moment and try again."],
    [error({ status: 500, code: "INTERNAL_ERROR" }), "CodeDNA is having trouble right now. Please try again shortly."],
    [error({ status: 502, code: "UNEXPECTED_RESPONSE" }), "CodeDNA is having trouble right now. Please try again shortly."],
    [error({ status: 503, code: "SERVICE_UNAVAILABLE" }), "CodeDNA is having trouble right now. Please try again shortly."],
    [error({ status: 200, code: "UNEXPECTED_RESPONSE" }), "CodeDNA returned an unexpected response. Please try again."],
    [error({ status: 409, code: "AI_ASSESSMENT_DISABLED" }), "AI interpretation is not enabled on this server."],
    [error({ status: 409, code: "ASSESSMENT_EVIDENCE_UNAVAILABLE" }), "There is no skill gap analysis that can be interpreted yet. Run a static analysis first."],
    [error({ status: 409, code: "ASSESSMENT_INPUT_TOO_LARGE" }), "The evidence of this analysis is too large for an AI interpretation."],
    [error({ status: 409, code: "CHALLENGE_EVALUATION_PENDING" }), "Your previous attempt is still being evaluated."],
    [error({ status: 409, code: "CHALLENGE_CLOSED" }), "This challenge is closed and accepts no further attempts."],
    [error({ status: 409, code: "CHALLENGE_EVALUATION_UNAVAILABLE" }), "Challenge evaluation is not available right now. Nothing was submitted."],
    [error({ status: 404, code: "RESOURCE_NOT_FOUND" }), "The request could not be completed."],
    [new Error("boom"), "Something went wrong. Please try again."],
  ])("describes %o", (input, expected) => {
    expect(describeApiError(input)).toBe(expected);
  });

  it("never echoes server-provided or exception text", () => {
    const leaky = error({ status: 500, code: "INTERNAL_ERROR" });
    Object.defineProperty(leaky, "message", { value: "SQLSTATE[42P01] at /var/www" });

    expect(describeApiError(leaky)).not.toMatch(/SQLSTATE|var\/www/);
  });
});

describe("shouldShowReference", () => {
  it("shows the request ID for unexpected failures but not for user input errors", () => {
    expect(shouldShowReference(error({ status: 500, code: "INTERNAL_ERROR", requestId: "rid" }))).toBe(true);
    expect(shouldShowReference(error({ status: 422, code: "VALIDATION_FAILED", requestId: "rid" }))).toBe(false);
    expect(shouldShowReference(error({ status: 422, code: "INVALID_CREDENTIALS", requestId: "rid" }))).toBe(false);
    expect(shouldShowReference(error({ status: null, code: "NETWORK_ERROR" }))).toBe(false);
  });
});
