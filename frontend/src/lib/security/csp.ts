/**
 * Content Security Policy for the production Next.js server (Phase 25,
 * docs/operations/security-baseline.md#content-security-policy).
 *
 * Scripts run only with this response's nonce ('strict-dynamic' lets those
 * scripts load the application's own chunks); no 'unsafe-inline' and no
 * 'unsafe-eval' for scripts. Next.js applies the nonce to its framework
 * scripts during server rendering, which is why every page renders
 * dynamically (src/app/layout.tsx).
 *
 * The one documented exception: inline style *attributes* (style-src-attr
 * 'unsafe-inline'), used for progress-bar widths and by the UI primitives for
 * positioning. Style attributes cannot run script; <style> elements still
 * need the nonce.
 *
 * Development keeps the Next.js dev server's needs (eval for React's debug
 * tooling, the hot-reload WebSocket) and is never served this policy.
 */
export function contentSecurityPolicy(nonce: string): string {
  if (!/^[A-Za-z0-9+/]{16,}={0,2}$/.test(nonce)) {
    throw new Error("Invalid CSP nonce");
  }
  return [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'`,
    `style-src 'self' 'nonce-${nonce}'`,
    "style-src-attr 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'",
    "media-src 'none'",
    "object-src 'none'",
    "frame-src 'none'",
    "worker-src 'self' blob:",
    "manifest-src 'self'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
    "upgrade-insecure-requests",
  ].join("; ");
}

/** A fresh, unpredictable nonce per response (128 bits from the platform CSPRNG). */
export function createNonce(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  let binary = "";
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary);
}
