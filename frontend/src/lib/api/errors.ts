import { API_ERROR_CODES, type ApiErrorCode, type ValidationErrors } from "./types";

/** Failures detected by the client itself, not reported by the API. */
export type ClientErrorCode = "NETWORK_ERROR" | "UNEXPECTED_RESPONSE";

/**
 * Any failed API call. `message` is for logs only. UI text always comes from
 * `describeApiError`, so server-provided text is never rendered verbatim.
 */
export class ApiError extends Error {
  readonly status: number | null;
  readonly code: ApiErrorCode | ClientErrorCode;
  readonly requestId: string | null;
  readonly fieldErrors: ValidationErrors;
  readonly retryAfterSeconds: number | null;

  constructor(init: {
    status: number | null;
    code: ApiErrorCode | ClientErrorCode;
    requestId?: string | null;
    fieldErrors?: ValidationErrors;
    retryAfterSeconds?: number | null;
  }) {
    super(`API request failed: ${init.code}${init.status ? ` (HTTP ${init.status})` : ""}`);
    this.name = "ApiError";
    this.status = init.status;
    this.code = init.code;
    this.requestId = init.requestId ?? null;
    this.fieldErrors = init.fieldErrors ?? {};
    this.retryAfterSeconds = init.retryAfterSeconds ?? null;
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}

export function isKnownErrorCode(value: unknown): value is ApiErrorCode {
  return typeof value === "string" && (API_ERROR_CODES as readonly string[]).includes(value);
}

/** User-facing text for an error. Never includes server-provided text. */
export function describeApiError(error: unknown): string {
  if (!isApiError(error)) {
    return "Something went wrong. Please try again.";
  }

  if (error.code === "NETWORK_ERROR") {
    return "Unable to connect to CodeDNA. Check your connection and try again.";
  }

  switch (error.code) {
    case "INVALID_CREDENTIALS":
      return "The email or password is incorrect.";
    case "VALIDATION_FAILED":
      return "Please correct the highlighted fields.";
    case "AUTHENTICATION_REQUIRED":
      return "Your session has ended. Please sign in again.";
    case "FORBIDDEN":
      return "You don't have permission to do that.";
    case "CSRF_TOKEN_MISMATCH":
      return "Your session expired. Please try again.";
    case "RATE_LIMITED":
      return error.retryAfterSeconds !== null
        ? `Too many attempts. Try again in ${formatSeconds(error.retryAfterSeconds)}.`
        : "Too many attempts. Please wait a moment and try again.";
    case "PAYLOAD_TOO_LARGE":
      return "The request is too large.";
    case "PROJECT_ARCHIVED":
      return "This project is archived. It keeps its history but cannot be changed or receive new source.";
    case "INVALID_SOURCE_TYPE":
      return "This project does not accept uploaded source.";
    case "IDEMPOTENCY_KEY_REUSED":
      return "This upload conflicts with an earlier one. Choose the file again and retry.";
    case "SOURCE_ARCHIVE_INVALID":
      return "The file is not a valid ZIP archive.";
    case "SOURCE_ARCHIVE_UNSAFE":
      return "The archive contains an unsafe entry (for example a symbolic link or a path outside the archive) and was rejected.";
    case "SOURCE_ARCHIVE_TOO_LARGE":
      return "The archive is larger than the upload limit.";
    case "SOURCE_UNCOMPRESSED_SIZE_EXCEEDED":
      return "The archive expands beyond the allowed total size.";
    case "SOURCE_FILE_COUNT_EXCEEDED":
      return "The archive contains more files than allowed.";
    case "SOURCE_FILE_TOO_LARGE":
      return "The archive contains a file larger than allowed.";
    case "AI_ASSESSMENT_DISABLED":
      return "AI interpretation is not enabled on this server.";
    case "ASSESSMENT_EVIDENCE_UNAVAILABLE":
      return "There is no skill gap analysis that can be interpreted yet. Run a static analysis first.";
    case "ASSESSMENT_INPUT_TOO_LARGE":
      return "The evidence of this analysis is too large for an AI interpretation.";
    case "CHALLENGES_DISABLED":
      return "Coding challenges are not enabled on this server.";
    case "CHALLENGE_NO_ELIGIBLE_GAP":
      return "There is no material skill gap with a matching challenge. Run a static analysis first.";
    case "CHALLENGE_NONE_AVAILABLE":
      return "Every challenge for this skill gap analysis has already been assigned.";
    case "CHALLENGE_EVALUATION_UNAVAILABLE":
      return "Challenge evaluation is not available right now. Nothing was submitted.";
    case "CHALLENGE_EVALUATION_PENDING":
      return "Your previous attempt is still being evaluated.";
    case "CHALLENGE_CLOSED":
      return "This challenge is closed and accepts no further attempts.";
  }

  if (error.status !== null && error.status >= 500) {
    return "CodeDNA is having trouble right now. Please try again shortly.";
  }

  if (error.code === "UNEXPECTED_RESPONSE") {
    return "CodeDNA returned an unexpected response. Please try again.";
  }

  return "The request could not be completed.";
}

/** Whether a reference (request ID) is worth showing for this error. */
export function shouldShowReference(error: ApiError): boolean {
  return error.requestId !== null && error.code !== "VALIDATION_FAILED" && error.code !== "INVALID_CREDENTIALS";
}

function formatSeconds(seconds: number): string {
  if (seconds < 60) {
    return `${seconds} second${seconds === 1 ? "" : "s"}`;
  }
  const minutes = Math.ceil(seconds / 60);
  return `${minutes} minute${minutes === 1 ? "" : "s"}`;
}
