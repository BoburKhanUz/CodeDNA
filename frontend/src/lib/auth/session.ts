import "server-only";

import { headers } from "next/headers";
import { cache } from "react";

import { ApiError } from "@/lib/api/errors";
import { parseApiResponse } from "@/lib/api/http";
import type { DataEnvelope, User } from "@/lib/api/types";
import { serverConfig } from "@/lib/config.server";

export type Session = { status: "authenticated"; user: User } | { status: "unauthenticated" };

/**
 * Resolves the visitor's session by asking Laravel (GET /api/v1/me) with the
 * browser's cookies. Laravel stays the source of truth; this exists so pages
 * can redirect before rendering.
 *
 * - No cookies at all: unauthenticated without calling the API.
 * - 200: authenticated. 401: unauthenticated.
 * - Anything else (network failure, 5xx, ...) throws, so it surfaces as an
 *   error page instead of silently looking like "logged out".
 *
 * Wrapped in React `cache`, so layouts and pages share one call per request.
 */
export const getSession = cache(async (): Promise<Session> => {
  const incoming = await headers();
  const cookie = incoming.get("cookie");
  if (!cookie) {
    return { status: "unauthenticated" };
  }

  const { backendUrl, publicOrigin } = serverConfig();
  const forwarded: Record<string, string> = {
    Accept: "application/json",
    Cookie: cookie,
    // Sanctum only uses the session for first-party (stateful) origins.
    Origin: publicOrigin,
    Referer: `${publicOrigin}/`,
  };
  const forwardedFor = incoming.get("x-forwarded-for");
  if (forwardedFor) {
    forwarded["X-Forwarded-For"] = forwardedFor;
  }

  let response: Response;
  try {
    response = await fetch(`${backendUrl}/api/v1/me`, { headers: forwarded, cache: "no-store" });
  } catch {
    throw new ApiError({ status: null, code: "NETWORK_ERROR" });
  }

  if (response.status === 401) {
    return { status: "unauthenticated" };
  }

  const body = await parseApiResponse<DataEnvelope<User>>(response);
  return { status: "authenticated", user: body.data };
});
