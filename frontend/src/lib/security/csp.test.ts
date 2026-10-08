import { describe, expect, it } from "vitest";

import { contentSecurityPolicy, createNonce } from "./csp";

function directives(policy: string): Map<string, string[]> {
  return new Map(
    policy.split("; ").map((directive) => {
      const [name, ...values] = directive.split(" ");
      return [name, values];
    }),
  );
}

describe("contentSecurityPolicy", () => {
  const nonce = "AAAAAAAAAAAAAAAAAAAAAA==";
  const policy = directives(contentSecurityPolicy(nonce));

  it("allows scripts only with the response nonce", () => {
    expect(policy.get("script-src")).toEqual(["'self'", `'nonce-${nonce}'`, "'strict-dynamic'"]);
    expect(contentSecurityPolicy(nonce)).not.toMatch(/unsafe-eval|unsafe-hashes|\*/);
    expect(policy.get("script-src")).not.toContain("'unsafe-inline'");
    expect(policy.get("style-src")).not.toContain("'unsafe-inline'");
  });

  it("allows inline style attributes only, never inline <style> without the nonce", () => {
    expect(policy.get("style-src-attr")).toEqual(["'unsafe-inline'"]);
    expect(policy.get("style-src")).toEqual(["'self'", `'nonce-${nonce}'`]);
  });

  it("forbids framing, plugins, foreign forms and <base>", () => {
    expect(policy.get("frame-ancestors")).toEqual(["'none'"]);
    expect(policy.get("object-src")).toEqual(["'none'"]);
    expect(policy.get("base-uri")).toEqual(["'self'"]);
    expect(policy.get("form-action")).toEqual(["'self'"]);
    expect(policy.get("default-src")).toEqual(["'self'"]);
    expect(policy.get("connect-src")).toEqual(["'self'"]);
    expect(policy.has("upgrade-insecure-requests")).toBe(true);
  });

  it("refuses a nonce that could inject directives", () => {
    for (const bad of ["", "short", "abc'; script-src *", "AAAAAAAAAAAAAAAAAAAAAA== 'unsafe-inline'"]) {
      expect(() => contentSecurityPolicy(bad)).toThrow("Invalid CSP nonce");
    }
  });
});

describe("createNonce", () => {
  it("is base64 of 128 random bits and differs every time", () => {
    const nonces = new Set(Array.from({ length: 50 }, () => createNonce()));
    expect(nonces.size).toBe(50);
    for (const nonce of nonces) {
      expect(nonce).toMatch(/^[A-Za-z0-9+/]{22}==$/);
      expect(() => contentSecurityPolicy(nonce)).not.toThrow();
    }
  });
});
