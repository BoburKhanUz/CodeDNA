import type { Metadata } from "next";

import { ChallengeDetail } from "@/components/challenge/challenge-detail";

export const metadata: Metadata = { title: "Coding Challenge" };

/** One coding challenge with its editor and attempts. IDs are validated by the client component; the API is owner-only. */
export default async function ProjectChallengePage({ params }: { params: Promise<{ project: string; challenge: string }> }) {
  const { project, challenge } = await params;
  return <ChallengeDetail projectId={project} challengeId={challenge} />;
}
