"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api/client";
import { isApiError } from "@/lib/api/errors";
import type { InvitationPreview } from "@/lib/api/types";
import { acceptInvitation, isInvitationToken, previewInvitation, ROLE_LABELS } from "@/lib/organizations/client";
import { forgetInvitation, pendingInvitation, rememberInvitation } from "@/lib/organizations/invitation-link";
import { formatDateTime } from "@/lib/projects/format";

type State =
  | { status: "loading" }
  | { status: "invalid" }
  | { status: "error"; error: unknown }
  | { status: "ready"; token: string; preview: InvitationPreview; signedIn: boolean };

const INVALID = Symbol("invalid invitation");

const CLOSED: Record<string, string> = {
  ACCEPTED: "This invitation has already been used.",
  REVOKED: "This invitation was withdrawn.",
  EXPIRED: "This invitation has expired. Ask the team for a new one.",
};

/**
 * /invitations/accept#<token> (docs/teams/invitations.md#links). The token
 * comes from the URL fragment (never sent to a server) or from this tab's
 * pending invitation, and is removed from the address bar at once. Shows
 * only the preview the API gives the link's holder; accepting needs the
 * invited account's session.
 */
export function InvitationAccept() {
  const router = useRouter();
  const [state, setState] = useState<State>({ status: "loading" });
  const [accepting, setAccepting] = useState(false);
  const [acceptError, setAcceptError] = useState<unknown>(null);

  useEffect(() => {
    const fromHash = window.location.hash.replace(/^#/, "");
    if (fromHash !== "") {
      window.history.replaceState(null, "", window.location.pathname);
      if (isInvitationToken(fromHash)) rememberInvitation(fromHash);
    }
    const token = isInvitationToken(fromHash) ? fromHash : pendingInvitation();
    const signedIn = () =>
      api.get("/api/v1/me").then(
        () => true,
        (error: unknown) => (isApiError(error) && error.status === 401 ? false : Promise.reject(error)),
      );
    (token === null ? Promise.reject(INVALID) : Promise.all([previewInvitation(token), signedIn()]))
      .then(([preview, isSignedIn]) => setState({ status: "ready", token: token as string, preview, signedIn: isSignedIn }))
      .catch((error: unknown) => {
        if (error === INVALID || (isApiError(error) && error.status === 404)) {
          forgetInvitation();
          setState({ status: "invalid" });
          return;
        }
        setState({ status: "error", error });
      });
  }, []);

  function accept(token: string) {
    setAccepting(true);
    setAcceptError(null);
    acceptInvitation(token)
      .then((result) => {
        forgetInvitation();
        router.replace(`/app/organizations/${result.organization.id}`);
      })
      .catch((error: unknown) => setAcceptError(error))
      .finally(() => setAccepting(false));
  }

  return (
    <main className="mx-auto grid min-h-svh max-w-md content-center gap-4 p-6">
      {state.status === "loading" ? <p role="status">Checking the invitation…</p> : null}
      {state.status === "invalid" ? (
        <Card data-testid="invitation-invalid">
          <CardHeader>
            <CardTitle>
              <h1>Invitation not found</h1>
            </CardTitle>
            <CardDescription>This invitation link is not valid. Check that you copied the whole link.</CardDescription>
          </CardHeader>
        </Card>
      ) : null}
      {state.status === "error" ? <ApiErrorAlert error={state.error} /> : null}
      {state.status === "ready" ? (
        <Card data-testid="invitation">
          <CardHeader>
            <CardTitle>
              <h1>{state.preview.organization !== null ? `Join ${state.preview.organization.name}` : "Team invitation"}</h1>
            </CardTitle>
            {state.preview.status === "PENDING" && state.preview.role !== null ? (
              <CardDescription>
                You are invited as {ROLE_LABELS[state.preview.role].toLowerCase()} ({state.preview.email_hint}). The invitation is valid until{" "}
                {formatDateTime(state.preview.expires_at)}.
              </CardDescription>
            ) : (
              <CardDescription data-testid="invitation-closed">{CLOSED[state.preview.status] ?? "This invitation can no longer be used."}</CardDescription>
            )}
          </CardHeader>
          {state.preview.status === "PENDING" ? (
            <CardContent className="grid gap-3">
              {acceptError !== null ? <ApiErrorAlert error={acceptError} /> : null}
              {state.signedIn ? (
                <Button className="w-fit" disabled={accepting} onClick={() => accept(state.token)}>
                  {accepting ? "Joining…" : "Accept invitation"}
                </Button>
              ) : (
                <>
                  <p className="text-sm">Sign in, or create an account, with the invited email address to accept.</p>
                  <div className="flex gap-2">
                    <Button asChild>
                      <Link href="/login">Sign in</Link>
                    </Button>
                    <Button asChild variant="outline">
                      <Link href="/register">Create account</Link>
                    </Button>
                  </div>
                </>
              )}
            </CardContent>
          ) : null}
        </Card>
      ) : null}
    </main>
  );
}
