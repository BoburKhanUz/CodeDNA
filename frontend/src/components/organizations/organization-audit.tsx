"use client";

import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { OrganizationFrame, useOrganizationErrors } from "@/components/organizations/organization-frame";
import { Button } from "@/components/ui/button";
import type { Organization, OrganizationAuditEvent, Paginated } from "@/lib/api/types";
import { isAdmin, listAuditEvents } from "@/lib/organizations/client";
import { formatDateTime } from "@/lib/projects/format";

const ACTION_LABELS: Record<string, string> = {
  ORGANIZATION_CREATED: "Team created",
  ORGANIZATION_UPDATED: "Team renamed",
  ORGANIZATION_ARCHIVED: "Team archived",
  MEMBER_INVITED: "Member invited",
  MEMBER_JOINED: "Member joined",
  MEMBER_ROLE_CHANGED: "Role changed",
  MEMBER_SUSPENDED: "Member suspended",
  MEMBER_REACTIVATED: "Member reactivated",
  MEMBER_REMOVED: "Member removed",
  INVITATION_REVOKED: "Invitation revoked",
  PROJECT_CREATED: "Project created",
  PROJECT_ARCHIVED: "Project archived",
};

type State = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; page: Paginated<OrganizationAuditEvent> };

/** /app/organizations/[organization]/audit: the append-only audit log, newest first (admins and the owner). */
export function OrganizationAudit({ organizationId }: { organizationId: string }) {
  return (
    <OrganizationFrame organizationId={organizationId} title="Audit log">
      {(organization) =>
        isAdmin(organization.role) ? (
          <Timeline organization={organization} />
        ) : (
          <p className="text-muted-foreground" data-testid="audit-forbidden">
            Only team admins and the owner can read the audit log.
          </p>
        )
      }
    </OrganizationFrame>
  );
}

function Timeline({ organization }: { organization: Organization }) {
  const [page, setPage] = useState(1);
  const [state, setState] = useState<State>({ status: "loading" });
  const fail = useOrganizationErrors(useCallback((error: unknown) => setState({ status: "error", error }), []));
  const load = useCallback(
    (n: number) => {
      listAuditEvents(organization.id, n)
        .then((result) => setState({ status: "ready", page: result }))
        .catch(fail);
    },
    [organization.id, fail],
  );
  useEffect(() => load(page), [load, page]);

  if (state.status === "loading") return <p role="status">Loading audit log…</p>;
  if (state.status === "error") return <ApiErrorAlert error={state.error} />;
  const { data, meta } = state.page;
  return (
    <div className="grid gap-4">
      <ol className="grid gap-2" data-testid="audit-events">
        {data.map((event) => (
          <li key={event.id} className="rounded-md border p-3 text-sm" data-action={event.action}>
            <p>
              <span className="font-medium">{ACTION_LABELS[event.action] ?? event.action}</span>
              {event.actor !== null ? ` by ${event.actor.name ?? "a former user"}` : ""}
            </p>
            <p className="text-muted-foreground text-xs">
              {formatDateTime(event.created_at)}
              {describe(event)}
            </p>
          </li>
        ))}
      </ol>
      {meta.last_page > 1 ? (
        <nav aria-label="Pagination" className="flex items-center gap-3 text-sm">
          <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => (setState({ status: "loading" }), setPage(meta.current_page - 1))}>
            Newer
          </Button>
          <span>
            Page {meta.current_page} of {meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => (setState({ status: "loading" }), setPage(meta.current_page + 1))}
          >
            Older
          </Button>
        </nav>
      ) : null}
    </div>
  );
}

/** A short, safe description from known metadata fields only. */
function describe(event: OrganizationAuditEvent): string {
  const m = event.metadata;
  const parts: string[] = [];
  if (typeof m.email === "string") parts.push(m.email);
  if (typeof m.name === "string") parts.push(m.name);
  if (typeof m.role === "string") parts.push(m.role.toLowerCase());
  if (typeof m.role === "object" && m.role !== null) {
    const role = m.role as { from?: unknown; to?: unknown };
    if (typeof role.from === "string" && typeof role.to === "string") parts.push(`${role.from.toLowerCase()} → ${role.to.toLowerCase()}`);
  }
  return parts.length > 0 ? ` · ${parts.join(" · ")}` : "";
}
