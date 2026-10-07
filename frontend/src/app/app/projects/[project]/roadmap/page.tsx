import type { Metadata } from "next";

import { RoadmapView } from "@/components/roadmap/roadmap-view";

export const metadata: Metadata = { title: "Learning Roadmap" };

/**
 * A project's learning roadmap: the newest one, or an earlier one with
 * ?roadmap=<id>. IDs are validated by the client component; the API is
 * owner-only.
 */
export default async function ProjectRoadmapPage({
  params,
  searchParams,
}: {
  params: Promise<{ project: string }>;
  searchParams: Promise<{ roadmap?: string | string[] }>;
}) {
  const { project } = await params;
  const { roadmap } = await searchParams;
  return <RoadmapView projectId={project} roadmapId={typeof roadmap === "string" ? roadmap : undefined} />;
}
