import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Don't advertise the framework in an X-Powered-By header.
  poweredByHeader: false,
  // Production image (Phase 25): a self-contained server with only the
  // runtime dependencies it traces (docker/node/Dockerfile, target production).
  output: "standalone",
};

export default nextConfig;
