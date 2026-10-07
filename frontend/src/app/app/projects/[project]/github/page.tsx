import type { Metadata } from "next";

import { ProjectGitHubView } from "@/components/github/project-github";

export const metadata: Metadata = { title: "GitHub" };

const NOTICES: Record<string, string> = { connected: "GitHub is connected. Choose a repository to import." };

/** A project's GitHub connection and imports. The ID is validated by the client component; the API is owner-only. */
export default async function ProjectGitHubPage({
  params,
  searchParams,
}: {
  params: Promise<{ project: string }>;
  searchParams: Promise<{ github?: string | string[] }>;
}) {
  const { project } = await params;
  const { github } = await searchParams;
  return <ProjectGitHubView projectId={project} notice={typeof github === "string" ? NOTICES[github] : undefined} />;
}
