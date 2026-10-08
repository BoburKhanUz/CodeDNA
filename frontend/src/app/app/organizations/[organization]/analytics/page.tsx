import type { Metadata } from "next";

import { OrganizationAnalytics } from "@/components/organizations/organization-analytics";

export const metadata: Metadata = { title: "Team analytics" };

/** Read-only team analytics. */
export default async function Page({ params }: { params: Promise<{ organization: string }> }) {
  const { organization } = await params;
  return <OrganizationAnalytics organizationId={organization} />;
}
