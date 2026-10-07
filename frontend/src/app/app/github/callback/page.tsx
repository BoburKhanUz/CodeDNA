import type { Metadata } from "next";

import { GitHubCallback } from "@/components/github/github-callback";

export const metadata: Metadata = { title: "Connecting GitHub" };

/**
 * Where GitHub sends the browser back after authorization. The values are
 * passed to the API, which verifies the single-use state; nothing here is trusted.
 */
export default async function GitHubCallbackPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const query = await searchParams;
  const one = (value: string | string[] | undefined): string | undefined => (typeof value === "string" ? value : undefined);
  return <GitHubCallback code={one(query.code)} state={one(query.state)} error={one(query.error)} />;
}
