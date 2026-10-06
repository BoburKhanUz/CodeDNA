import type { Metadata } from "next";

import { DnaDashboard } from "@/components/dna/dna-dashboard";

export const metadata: Metadata = { title: "CodeDNA" };

/** One CodeDNA assessment of a project, from its history. */
export default async function ProjectDnaSnapshotPage({ params }: { params: Promise<{ project: string; snapshot: string }> }) {
  const { project, snapshot } = await params;
  return <DnaDashboard projectId={project} snapshotId={snapshot} />;
}
