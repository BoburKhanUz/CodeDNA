import { createRequire } from "node:module";

import { afterEach, describe, expect, it, vi } from "vitest";

import nextConfig, { OAUTH_CALLBACK_REQUEST } from "../../next.config";

// The development server's own request logger, exercised as Next.js calls it.
const require = createRequire(import.meta.url);
const { logRequests } = require("next/dist/server/dev/log-requests") as {
  logRequests: (request: { url: string }, response: { statusCode: number }, logging: unknown, start: number, end: number) => void;
};

const CODE = "synthetic-oauth-code-7f3a9c";
const STATE = "SyntheticState0123456789abcdefghijklmnopqrs";

function logged(url: string, statusCode = 200): string {
  const out: string[] = [];
  const write = vi.spyOn(process.stdout, "write").mockImplementation((chunk: string | Uint8Array) => {
    out.push(String(chunk));
    return true;
  });
  try {
    logRequests({ url }, { statusCode }, nextConfig.logging, 0, 5);
  } finally {
    write.mockRestore();
  }
  return out.join("");
}

afterEach(() => vi.restoreAllMocks());

describe("OAuth callback secrets in the development request log", () => {
  const callbacks = ["/app/github/callback", "/app/integrations/gitlab/callback", "/app/integrations/bitbucket/callback"];

  it.each(callbacks)("never logs %s with its code and state, on success or error", (path) => {
    for (const [url, status] of [
      [`${path}?code=${CODE}&state=${STATE}`, 200],
      [`${path}?code=${CODE}&state=${STATE}&installation_id=1&setup_action=install`, 307],
      [`${path}?error=access_denied&error_description=denied&state=${STATE}`, 200],
      [`${path}/?code=${CODE}&state=${STATE}`, 308],
      [`${path}?_rsc=abc&code=${CODE}&state=${STATE}`, 500],
      [`${path.toUpperCase()}?code=${CODE}`, 404],
    ] as const) {
      const output = logged(url, status);
      expect(output).not.toContain(CODE);
      expect(output).not.toContain(STATE);
    }
  });

  it("still logs every other request, so ordinary diagnostics remain", () => {
    for (const url of ["/app/projects?page=2", "/app/projects/01jabcdefghjkmnpqrstvwxyz0/repositories?provider=connected", "/login", "/app/github", "/app/integrations/gitlab/callbacks-elsewhere?x=1"]) {
      expect(logged(url, 200)).toContain(url.split("?")[0]);
    }
    // The control: without the ignore rule, Next.js prints the full callback URL.
    const out: string[] = [];
    const write = vi.spyOn(process.stdout, "write").mockImplementation((chunk: string | Uint8Array) => {
      out.push(String(chunk));
      return true;
    });
    logRequests({ url: `/app/integrations/gitlab/callback?code=${CODE}&state=${STATE}` }, { statusCode: 200 }, {}, 0, 5);
    write.mockRestore();
    expect(out.join("")).toContain(CODE);
  });

  it("matches only the callback routes", () => {
    expect(OAUTH_CALLBACK_REQUEST.test("/app/github/callback")).toBe(true);
    expect(OAUTH_CALLBACK_REQUEST.test("/app/integrations/bitbucket/callback?code=x")).toBe(true);
    expect(OAUTH_CALLBACK_REQUEST.test("/app/github/callbackx")).toBe(false);
    expect(OAUTH_CALLBACK_REQUEST.test("/app/projects/x/github/callback")).toBe(false);
    expect(OAUTH_CALLBACK_REQUEST.test("/api/v1/github/callback")).toBe(false);
  });
});
