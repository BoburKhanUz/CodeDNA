"use client";

import { type FormEvent, useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import { OrganizationFrame, useOrganizationErrors } from "@/components/organizations/organization-frame";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { useCurrentUser } from "@/components/auth/auth-provider";
import type { CreatedInvitation, Organization, OrganizationInvitation, OrganizationMembership, OrganizationRole } from "@/lib/api/types";
import {
  assignableRoles,
  canManage,
  inviteMember,
  isAdmin,
  listInvitations,
  listMembers,
  removeMember,
  revokeInvitation,
  ROLE_LABELS,
  updateMember,
} from "@/lib/organizations/client";
import { invitationLink } from "@/lib/organizations/invitation-link";
import { formatDate, formatDateTime } from "@/lib/projects/format";

/** /app/organizations/[organization]/members: members for everyone; management and invitations for admins and the owner. */
export function OrganizationMembers({ organizationId }: { organizationId: string }) {
  return (
    <OrganizationFrame organizationId={organizationId} title="Members">
      {(organization) => (
        <div className="grid gap-6">
          <MemberTable organization={organization} />
          {isAdmin(organization.role) ? <Invitations organization={organization} /> : null}
        </div>
      )}
    </OrganizationFrame>
  );
}

type Load<T> = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; items: T[] };

function MemberTable({ organization }: { organization: Organization }) {
  const me = useCurrentUser();
  const [state, setState] = useState<Load<OrganizationMembership>>({ status: "loading" });
  const [actionError, setActionError] = useState<unknown>(null);
  const fail = useOrganizationErrors(useCallback((error: unknown) => setState({ status: "error", error }), []));

  const load = useCallback(() => {
    listMembers(organization.id)
      .then((page) => setState({ status: "ready", items: page.data }))
      .catch(fail);
  }, [organization.id, fail]);
  useEffect(() => load(), [load]);

  function act(change: Promise<unknown>) {
    setActionError(null);
    change.then(load).catch((error: unknown) => setActionError(error));
  }

  if (state.status === "loading") return <p role="status">Loading members…</p>;
  if (state.status === "error") return <ApiErrorAlert error={state.error} />;
  const editable = organization.status === "ACTIVE";

  return (
    <div className="grid gap-3">
      {actionError !== null ? <ApiErrorAlert error={actionError} /> : null}
      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full text-left text-sm" data-testid="members">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-2 font-medium">Member</th>
              <th scope="col" className="px-4 py-2 font-medium">Role</th>
              <th scope="col" className="px-4 py-2 font-medium">Status</th>
              <th scope="col" className="px-4 py-2 font-medium">Joined</th>
              <th scope="col" className="px-4 py-2 font-medium">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {state.items.map((member) => {
              const manageable = editable && canManage(organization.role, member, member.user.id === me.id);
              return (
                <tr key={member.id} className="border-t" data-testid="member" data-role={member.role}>
                  <td className="px-4 py-2">
                    <span className="font-medium">{member.user.name}</span>
                    {member.user.email !== null ? <p className="text-muted-foreground text-xs">{member.user.email}</p> : null}
                  </td>
                  <td className="px-4 py-2">
                    {manageable ? (
                      <NativeSelect
                        aria-label={`Role of ${member.user.name}`}
                        value={member.role}
                        onChange={(e) => act(updateMember(organization.id, member.id, { role: e.target.value as Exclude<OrganizationRole, "OWNER"> }))}
                      >
                        {assignableRoles(organization.role).map((role) => (
                          <option key={role} value={role}>
                            {ROLE_LABELS[role]}
                          </option>
                        ))}
                      </NativeSelect>
                    ) : (
                      ROLE_LABELS[member.role]
                    )}
                  </td>
                  <td className="px-4 py-2">{member.status === "ACTIVE" ? "Active" : "Suspended"}</td>
                  <td className="px-4 py-2 whitespace-nowrap">{formatDate(member.joined_at)}</td>
                  <td className="px-4 py-2">
                    {manageable ? (
                      <div className="flex justify-end gap-2">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => act(updateMember(organization.id, member.id, { status: member.status === "ACTIVE" ? "SUSPENDED" : "ACTIVE" }))}
                        >
                          {member.status === "ACTIVE" ? "Suspend" : "Reactivate"}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => act(removeMember(organization.id, member.id))}>
                          Remove
                        </Button>
                      </div>
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Invitations({ organization }: { organization: Organization }) {
  const [state, setState] = useState<Load<OrganizationInvitation>>({ status: "loading" });
  const [created, setCreated] = useState<CreatedInvitation | null>(null);
  const [actionError, setActionError] = useState<unknown>(null);
  const fail = useOrganizationErrors(useCallback((error: unknown) => setState({ status: "error", error }), []));

  const load = useCallback(() => {
    listInvitations(organization.id)
      .then((page) => setState({ status: "ready", items: page.data }))
      .catch(fail);
  }, [organization.id, fail]);
  useEffect(() => load(), [load]);

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Invitations</h2>
        </CardTitle>
        <CardDescription>Invitations expire after 72 hours and can be used once, by the invited email address only.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {organization.status === "ACTIVE" ? (
          <InviteForm
            organization={organization}
            onInvited={(invitation) => {
              setCreated(invitation);
              load();
            }}
          />
        ) : null}
        {created !== null ? <InvitationLink invitation={created} onDone={() => setCreated(null)} /> : null}
        {actionError !== null ? <ApiErrorAlert error={actionError} /> : null}
        {state.status === "loading" ? <p role="status">Loading invitations…</p> : null}
        {state.status === "error" ? <ApiErrorAlert error={state.error} /> : null}
        {state.status === "ready" && state.items.length === 0 ? <p className="text-muted-foreground text-sm">No invitations yet.</p> : null}
        {state.status === "ready" && state.items.length > 0 ? (
          <ul className="grid gap-2 text-sm" data-testid="invitations">
            {state.items.map((invitation) => (
              <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3" data-status={invitation.status}>
                <span>
                  <span className="font-medium">{invitation.email}</span> · {ROLE_LABELS[invitation.role]} · {invitation.status.toLowerCase()}
                  {invitation.status === "PENDING" ? ` · expires ${formatDateTime(invitation.expires_at)}` : ""}
                </span>
                {invitation.status === "PENDING" && organization.status === "ACTIVE" ? (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setActionError(null);
                      revokeInvitation(organization.id, invitation.id)
                        .then(load)
                        .catch((error: unknown) => setActionError(error));
                    }}
                  >
                    Revoke
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
      </CardContent>
    </Card>
  );
}

function InviteForm({ organization, onInvited }: { organization: Organization; onInvited: (invitation: CreatedInvitation) => void }) {
  const roles = assignableRoles(organization.role);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<Exclude<OrganizationRole, "OWNER">>("MEMBER");
  const [error, setError] = useState<unknown>(null);
  const [sending, setSending] = useState(false);

  function submit(event: FormEvent) {
    event.preventDefault();
    setSending(true);
    setError(null);
    inviteMember(organization.id, email.trim(), role)
      .then((invitation) => {
        setEmail("");
        onInvited(invitation);
      })
      .catch((e: unknown) => setError(e))
      .finally(() => setSending(false));
  }

  return (
    <form onSubmit={submit} className="grid max-w-md gap-3" noValidate>
      {error !== null ? <ApiErrorAlert error={error} /> : null}
      <FormField id="invite-email" label="Email address" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
      <div className="grid gap-2">
        <Label htmlFor="invite-role">Role</Label>
        <NativeSelect id="invite-role" value={role} onChange={(e) => setRole(e.target.value as Exclude<OrganizationRole, "OWNER">)}>
          {roles.map((r) => (
            <option key={r} value={r}>
              {ROLE_LABELS[r]}
            </option>
          ))}
        </NativeSelect>
      </div>
      <Button type="submit" className="w-fit" disabled={sending || email.trim() === ""}>
        {sending ? "Inviting…" : "Create invitation"}
      </Button>
    </form>
  );
}

/** The link is shown once: only its hash is stored, so it cannot be shown again. */
function InvitationLink({ invitation, onDone }: { invitation: CreatedInvitation; onDone: () => void }) {
  const link = invitationLink(window.location.origin, invitation.token);
  const [copied, setCopied] = useState(false);
  return (
    <div role="status" className="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-50" data-testid="invitation-link">
      <p>
        Send this link to <span className="font-medium">{invitation.email}</span>. It is shown only now and works once, until{" "}
        {formatDateTime(invitation.expires_at)}.
      </p>
      <code className="block overflow-x-auto rounded bg-white/60 p-2 text-xs dark:bg-black/30">{link}</code>
      <div className="flex gap-2">
        <Button
          size="sm"
          variant="outline"
          onClick={() => {
            void navigator.clipboard?.writeText(link).then(() => setCopied(true));
          }}
        >
          {copied ? "Copied" : "Copy link"}
        </Button>
        <Button size="sm" variant="ghost" onClick={onDone}>
          Done
        </Button>
      </div>
    </div>
  );
}
