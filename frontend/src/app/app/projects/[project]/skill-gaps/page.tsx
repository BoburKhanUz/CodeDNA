import type { Metadata } from "next";

import { SkillGapAnalysis } from "@/components/skill-gap/skill-gap-analysis";

export const metadata: Metadata = { title: "Skill Gaps" };

/** The newest skill gap analysis of a project. The ID is validated by the client component; the API is owner-only. */
export default async function ProjectSkillGapsPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <SkillGapAnalysis projectId={project} />;
}
