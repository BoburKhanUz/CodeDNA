import { NextRequest } from "next/server";
import { afterEach, describe, expect, it, vi } from "vitest";

import { proxy } from "./proxy";

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("proxy", () => {
  it("sends a fresh nonce-based policy on every production response", () => {
    vi.stubEnv("NODE_ENV", "production");
    const first = proxy(new NextRequest("https://app.example.test/app"));
    const second = proxy(new NextRequest("https://app.example.test/app"));

    const policy = first.headers.get("Content-Security-Policy");
    expect(policy).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9+/]{22}==' 'strict-dynamic'/);
    expect(second.headers.get("Content-Security-Policy")).not.toBe(policy);
    // Next.js reads the nonce from the forwarded request headers.
    expect(first.headers.get("x-middleware-request-content-security-policy")).toBe(policy);
  });

  it("leaves the development server alone", () => {
    vi.stubEnv("NODE_ENV", "development");
    expect(proxy(new NextRequest("http://localhost/app")).headers.get("Content-Security-Policy")).toBeNull();
  });
});
