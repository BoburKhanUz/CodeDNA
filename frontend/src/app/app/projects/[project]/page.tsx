import type { Metadata } from "next";

import { ProjectDetail } from "@/components/projects/project-detail";

export const metadata: Metadata = { title: "Project" };

/** The ID is passed to the client component, which validates it and asks the API (owner-only). */
export default async function ProjectPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <ProjectDetail projectId={project} />;
}
