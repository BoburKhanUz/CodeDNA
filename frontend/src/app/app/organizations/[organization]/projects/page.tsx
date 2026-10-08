import type { Metadata } from "next";

import { OrganizationProjects } from "@/components/organizations/organization-projects";

export const metadata: Metadata = { title: "Team projects" };

/** The team's projects. */
export default async function Page({ params }: { params: Promise<{ organization: string }> }) {
  const { organization } = await params;
  return <OrganizationProjects organizationId={organization} />;
}
