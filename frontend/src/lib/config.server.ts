import "server-only";

/**
 * Server-only configuration (never bundled for the browser). Values are read
 * from the container environment and validated on first use so a missing or
 * malformed variable fails with a clear message.
 */
export interface ServerConfig {
  /** How the Next.js server reaches the API internally (e.g. http://nginx). */
  backendUrl: string;
  /** The public browser origin (e.g. http://localhost); sent as Origin so Sanctum treats forwarded requests as first-party. */
  publicOrigin: string;
}

export function serverConfig(): ServerConfig {
  return {
    backendUrl: requiredUrl("BACKEND_INTERNAL_URL"),
    publicOrigin: requiredUrl("FRONTEND_URL"),
  };
}

function requiredUrl(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} is not set (see .env.example).`);
  }
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`${name} must be an absolute http(s) URL, got "${value}".`);
  }
  if (url.protocol !== "http:" && url.protocol !== "https:") {
    throw new Error(`${name} must be an absolute http(s) URL, got "${value}".`);
  }
  return url.origin;
}
