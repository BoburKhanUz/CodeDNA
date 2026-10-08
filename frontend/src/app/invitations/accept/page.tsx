import type { Metadata } from "next";

import { InvitationAccept } from "@/components/organizations/invitation-accept";

export const metadata: Metadata = { title: "Team invitation" };

/**
 * /invitations/accept#<token>: public, so a visitor without an account can
 * see the invitation before signing in (Phase 24). The token stays in the
 * URL fragment, which is never sent to the server.
 */
export default function InvitationAcceptPage() {
  return <InvitationAccept />;
}
