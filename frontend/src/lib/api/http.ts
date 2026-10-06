import { ApiError, isKnownErrorCode } from "./errors";
import type { ApiErrorCode, ValidationErrors } from "./types";

/**
 * Turns a fetch Response into the parsed body, or throws an ApiError that
 * understands the Laravel error envelope. Shared by the browser client and
 * server-side calls.
 */
export async function parseApiResponse<T>(response: Response): Promise<T> {
  const headerRequestId = response.headers.get("x-request-id");

  if (response.status === 204) {
    return undefined as T;
  }

  const body = await readJson(response);

  if (response.ok) {
    if (body === undefined) {
      throw new ApiError({ status: response.status, code: "UNEXPECTED_RESPONSE", requestId: headerRequestId });
    }
    return body as T;
  }

  const envelope = asErrorEnvelope(body);

  throw new ApiError({
    status: response.status,
    // Non-envelope errors (e.g. a proxy's HTML 502 page) are "unexpected".
    code: envelope?.code ?? "UNEXPECTED_RESPONSE",
    requestId: envelope?.requestId ?? headerRequestId,
    fieldErrors: envelope?.fields ?? {},
    retryAfterSeconds: parseRetryAfter(response.headers.get("retry-after")),
  });
}

async function readJson(response: Response): Promise<unknown> {
  const contentType = response.headers.get("content-type") ?? "";
  if (!contentType.includes("application/json")) {
    return undefined;
  }
  try {
    return await response.json();
  } catch {
    return undefined;
  }
}

function asErrorEnvelope(
  body: unknown,
): { code: ApiErrorCode; requestId: string | null; fields: ValidationErrors } | null {
  if (typeof body !== "object" || body === null || !("error" in body)) {
    return null;
  }
  const error = (body as { error: unknown }).error;
  if (typeof error !== "object" || error === null) {
    return null;
  }
  const { code, request_id: requestId, details } = error as Record<string, unknown>;
  if (!isKnownErrorCode(code)) {
    return null;
  }
  return {
    code,
    requestId: typeof requestId === "string" ? requestId : null,
    fields: sanitizeFields((details as { fields?: unknown } | undefined)?.fields),
  };
}

function sanitizeFields(fields: unknown): ValidationErrors {
  if (typeof fields !== "object" || fields === null) {
    return {};
  }
  const result: ValidationErrors = {};
  for (const [name, messages] of Object.entries(fields)) {
    if (Array.isArray(messages)) {
      result[name] = messages.filter((message): message is string => typeof message === "string");
    }
  }
  return result;
}

/** Retry-After in seconds (Laravel sends delta-seconds). */
export function parseRetryAfter(value: string | null): number | null {
  if (value === null || !/^\d+$/.test(value.trim())) {
    return null;
  }
  return Number.parseInt(value, 10);
}
