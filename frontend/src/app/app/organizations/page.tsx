import type { Metadata } from "next";

import { OrganizationList } from "@/components/organizations/organization-list";

export const metadata: Metadata = { title: "Teams" };

/** /app/organizations: the teams the user belongs to (Phase 24). */
export default function Page() {
  return <OrganizationList />;
}
