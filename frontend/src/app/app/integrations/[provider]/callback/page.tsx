import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { ProviderCallback } from "@/components/repository-providers/provider-callback";
import { isRepositoryProvider } from "@/lib/repository-providers/client";

export const metadata: Metadata = { title: "Connecting repository provider" };

/**
 * Where GitLab or Bitbucket Cloud sends the browser back after authorization
 * (the configured *_CALLBACK_URL). The values are passed to the API, which
 * verifies the single-use state; nothing here is trusted.
 */
export default async function ProviderCallbackPage({
  params,
  searchParams,
}: {
  params: Promise<{ provider: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { provider } = await params;
  if (!isRepositoryProvider(provider)) notFound();
  const query = await searchParams;
  const one = (value: string | string[] | undefined): string | undefined => (typeof value === "string" ? value : undefined);
  return <ProviderCallback provider={provider} code={one(query.code)} state={one(query.state)} error={one(query.error)} />;
}
