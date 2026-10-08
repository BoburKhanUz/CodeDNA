import type { Metadata } from "next";

import { OrganizationMembers } from "@/components/organizations/organization-members";

export const metadata: Metadata = { title: "Team members" };

/** Team members and, for admins and the owner, invitations. */
export default async function Page({ params }: { params: Promise<{ organization: string }> }) {
  const { organization } = await params;
  return <OrganizationMembers organizationId={organization} />;
}
