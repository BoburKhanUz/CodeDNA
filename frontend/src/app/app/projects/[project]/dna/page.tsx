import type { Metadata } from "next";

import { DnaDashboard } from "@/components/dna/dna-dashboard";

export const metadata: Metadata = { title: "CodeDNA" };

/** The newest CodeDNA assessment of a project. IDs are validated by the client component; the API is owner-only. */
export default async function ProjectDnaPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <DnaDashboard projectId={project} />;
}
