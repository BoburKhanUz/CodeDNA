import type { ApiErrorCode, User, ValidationErrors } from "@/lib/api/types";

/** Builders for responses shaped exactly like the Laravel API's. */

export function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json", ...headers },
  });
}

export function apiErrorResponse(
  status: number,
  code: ApiErrorCode,
  options: { requestId?: string; fields?: ValidationErrors; headers?: Record<string, string> } = {},
): Response {
  const requestId = options.requestId ?? "11111111-2222-4333-8444-555555555555";
  const error: Record<string, unknown> = { code, message: "server message", request_id: requestId };
  if (options.fields) error.details = { fields: options.fields };
  return jsonResponse({ error }, status, { "x-request-id": requestId, ...options.headers });
}

export function noContent(): Response {
  return new Response(null, { status: 204 });
}

export const user: User = {
  id: "01k6m2y5a7j1x9v3q8n4r2t6wz",
  type: "user",
  name: "Ada Lovelace",
  email: "ada@example.com",
  email_verified_at: null,
  created_at: "2026-10-05T12:00:00Z",
};
