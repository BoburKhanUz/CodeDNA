import type { Metadata } from "next";

import { OrganizationOverview } from "@/components/organizations/organization-overview";

export const metadata: Metadata = { title: "Team" };

/** A team overview; members only (the API answers 404 to anyone else). */
export default async function Page({ params }: { params: Promise<{ organization: string }> }) {
  const { organization } = await params;
  return <OrganizationOverview organizationId={organization} />;
}
