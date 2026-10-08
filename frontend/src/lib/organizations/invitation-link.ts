import { isInvitationToken } from "@/lib/organizations/client";

/**
 * Invitation links (docs/teams/invitations.md#links) carry the token in the
 * URL fragment (/invitations/accept#<token>): browsers never send fragments
 * to a server, so the token reaches no access log and no Referer header.
 * While the visitor signs in or registers, the token waits in this tab's
 * sessionStorage, and is forgotten once used.
 */
const KEY = "codedna.pendingInvitation";

export const ACCEPT_PATH = "/invitations/accept";

export function invitationLink(origin: string, token: string): string {
  return `${origin}${ACCEPT_PATH}#${token}`;
}

export function rememberInvitation(token: string): void {
  try {
    window.sessionStorage.setItem(KEY, token);
  } catch {
    // Storage unavailable: the visitor opens the link again after signing in.
  }
}

export function pendingInvitation(): string | null {
  try {
    const token = window.sessionStorage.getItem(KEY);
    return token !== null && isInvitationToken(token) ? token : null;
  } catch {
    return null;
  }
}

export function forgetInvitation(): void {
  try {
    window.sessionStorage.removeItem(KEY);
  } catch {
    // Nothing to forget.
  }
}

/** Where to go after signing in or registering: back to a pending invitation, or the app. */
export function afterSignInPath(): string {
  return pendingInvitation() === null ? "/app" : ACCEPT_PATH;
}
