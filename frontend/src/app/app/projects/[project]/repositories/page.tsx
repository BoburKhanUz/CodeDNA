import type { Metadata } from "next";

import { ProjectRepositoryProviderView } from "@/components/repository-providers/project-repository-provider";

export const metadata: Metadata = { title: "GitLab and Bitbucket" };

const NOTICES: Record<string, string> = { connected: "Your account is connected. Choose a repository to import." };

/** A project's GitLab or Bitbucket Cloud connection and imports. The ID is validated by the client component; the API checks access. */
export default async function ProjectRepositoriesPage({
  params,
  searchParams,
}: {
  params: Promise<{ project: string }>;
  searchParams: Promise<{ provider?: string | string[] }>;
}) {
  const { project } = await params;
  const { provider } = await searchParams;
  return <ProjectRepositoryProviderView projectId={project} notice={typeof provider === "string" ? NOTICES[provider] : undefined} />;
}
