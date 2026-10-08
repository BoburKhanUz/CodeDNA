import { NextResponse, type NextRequest } from "next/server";

import { contentSecurityPolicy, createNonce } from "@/lib/security/csp";

/**
 * Production Content Security Policy with a per-response nonce (Phase 25).
 * Next.js reads the policy from the request headers during rendering and
 * applies the nonce to the scripts it emits; the same policy is sent to the
 * browser. The development server (eval, hot reload) is left alone.
 */
export function proxy(request: NextRequest): NextResponse {
  if (process.env.NODE_ENV !== "production") {
    return NextResponse.next();
  }
  const policy = contentSecurityPolicy(createNonce());
  const headers = new Headers(request.headers);
  headers.set("Content-Security-Policy", policy);
  const response = NextResponse.next({ request: { headers } });
  response.headers.set("Content-Security-Policy", policy);
  return response;
}

export const config = {
  // Pages only: static assets carry no executable HTML, and prefetches reuse
  // the page's policy.
  matcher: [
    {
      source: "/((?!_next/static|_next/image|favicon.ico).*)",
      missing: [
        { type: "header", key: "next-router-prefetch" },
        { type: "header", key: "purpose", value: "prefetch" },
      ],
    },
  ],
};
