import type { Metadata } from "next";

import { BillingView } from "@/components/billing/billing-view";

export const metadata: Metadata = { title: "Billing" };

/** /app/billing: the caller's plan, usage and the plan catalog (Phase 23). */
export default function BillingPage() {
  return <BillingView />;
}
