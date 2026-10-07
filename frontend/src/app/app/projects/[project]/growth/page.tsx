import type { Metadata } from "next";

import { GrowthView } from "@/components/growth/growth-view";

export const metadata: Metadata = { title: "Growth" };

/**
 * A project's growth between code assessments: the newest one, or an
 * earlier one with ?snapshot=<id>. IDs are validated by the client
 * component; the API is owner-only.
 */
export default async function ProjectGrowthPage({
  params,
  searchParams,
}: {
  params: Promise<{ project: string }>;
  searchParams: Promise<{ snapshot?: string | string[] }>;
}) {
  const { project } = await params;
  const { snapshot } = await searchParams;
  return <GrowthView projectId={project} snapshotId={typeof snapshot === "string" ? snapshot : undefined} />;
}
