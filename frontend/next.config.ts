import type { NextConfig } from "next";

/**
 * OAuth callback pages (GitHub, GitLab, Bitbucket Cloud) receive the
 * authorization code and state in their query string. The development
 * server's request log prints each request URL verbatim and offers no
 * redaction, only an ignore list: these requests are left out of it, every
 * other request is still logged. (The production server has no request log;
 * Nginx logs paths only.)
 */
export const OAUTH_CALLBACK_REQUEST = /^\/app\/(?:github|integrations\/[^/?#]+)\/callback(?:[/?#]|$)/i;

const nextConfig: NextConfig = {
  // Don't advertise the framework in an X-Powered-By header.
  poweredByHeader: false,
  // Production image (Phase 25): a self-contained server with only the
  // runtime dependencies it traces (docker/node/Dockerfile, target production).
  output: "standalone",
  logging: {
    incomingRequests: { ignore: [OAUTH_CALLBACK_REQUEST] },
  },
};

export default nextConfig;
