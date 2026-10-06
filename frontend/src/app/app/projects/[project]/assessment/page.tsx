import type { Metadata } from "next";

import { AssessmentView } from "@/components/assessment/assessment-view";

export const metadata: Metadata = { title: "AI Assessment" };

/** The newest AI assessment of a project. The ID is validated by the client component; the API is owner-only. */
export default async function ProjectAssessmentPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <AssessmentView projectId={project} />;
}
