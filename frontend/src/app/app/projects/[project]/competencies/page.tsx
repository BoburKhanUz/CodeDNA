import type { Metadata } from "next";

import { CompetencyMatrix } from "@/components/competency/competency-matrix";

export const metadata: Metadata = { title: "Competency Matrix" };

/** The newest competency matrix of a project. The ID is validated by the client component; the API is owner-only. */
export default async function ProjectCompetenciesPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <CompetencyMatrix projectId={project} />;
}
