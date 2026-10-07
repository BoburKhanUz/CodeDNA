import type { Metadata } from "next";

import { ChallengeList } from "@/components/challenge/challenge-list";

export const metadata: Metadata = { title: "Coding Challenges" };

/** A project's coding challenges. The ID is validated by the client component; the API is owner-only. */
export default async function ProjectChallengesPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <ChallengeList projectId={project} />;
}
