import type { Metadata } from "next";

import { OrganizationAudit } from "@/components/organizations/organization-audit";

export const metadata: Metadata = { title: "Team audit log" };

/** The team's audit log (admins and the owner). */
export default async function Page({ params }: { params: Promise<{ organization: string }> }) {
  const { organization } = await params;
  return <OrganizationAudit organizationId={organization} />;
}
