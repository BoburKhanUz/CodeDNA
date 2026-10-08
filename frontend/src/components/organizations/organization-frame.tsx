"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { type ReactNode, useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { Organization } from "@/lib/api/types";
import { getOrganization, isAdmin, isOrganizationId, ROLE_LABELS } from "@/lib/organizations/client";

type State = { status: "loading" } | { status: "not-found" } | { status: "error"; error: unknown } | { status: "ready"; organization: Organization };

/** Handles a request's error the same way on every team page: sign in again on 401, not found on 404. */
export function useOrganizationErrors(onError: (error: unknown) => void): (error: unknown) => void {
  const router = useRouter();
  return useCallback(
    (error: unknown) => {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      onError(error);
    },
    [router, onError],
  );
}

/**
 * A team page (Phase 24): loads the organization (members only; anyone
 * else gets "not found", as the API answers 404), then renders the header,
 * the team navigation and the section. The navigation shows the audit log
 * to admins only; the server enforces every rule regardless.
 */
export function OrganizationFrame({
  organizationId,
  title,
  children,
}: {
  organizationId: string;
  title: string;
  children: (organization: Organization, reload: () => void) => ReactNode;
}) {
  const valid = isOrganizationId(organizationId);
  const [state, setState] = useState<State>(() => (valid ? { status: "loading" } : { status: "not-found" }));
  const fail = useOrganizationErrors(
    useCallback((error: unknown) => setState(isApiError(error) && error.status === 404 ? { status: "not-found" } : { status: "error", error }), []),
  );

  const load = useCallback(() => {
    if (!valid) return;
    getOrganization(organizationId)
      .then((organization) => setState({ status: "ready", organization }))
      .catch(fail);
  }, [organizationId, valid, fail]);

  useEffect(() => load(), [load]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-5xl gap-6" role="status" aria-label="Loading team">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-40" />
        <span className="sr-only">Loading team…</span>
      </div>
    );
  }
  if (state.status === "not-found") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">Team not found</h1>
        <p className="text-muted-foreground">This team does not exist, or you are not a member of it.</p>
        <Button asChild variant="outline" className="w-fit">
          <Link href="/app/organizations">Back to teams</Link>
        </Button>
      </div>
    );
  }
  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        <ApiErrorAlert error={state.error} />
        <Button variant="outline" className="w-fit" onClick={() => (setState({ status: "loading" }), load())}>
          Try again
        </Button>
      </div>
    );
  }

  const { organization } = state;
  return (
    <div className="grid max-w-5xl gap-6">
      <div className="grid gap-2">
        <p className="text-muted-foreground text-sm">
          <Link href="/app/organizations" className="underline-offset-4 hover:underline">
            Teams
          </Link>{" "}
          / {organization.name}
        </p>
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        <p className="flex flex-wrap items-center gap-2 text-sm">
          <span className="bg-muted rounded-full px-2.5 py-0.5 text-xs font-medium" data-testid="organization-role">
            {ROLE_LABELS[organization.role]}
          </span>
          {organization.status !== "ACTIVE" ? (
            <span className="rounded-full border px-2.5 py-0.5 text-xs font-medium" data-testid="organization-status">
              {organization.status === "ARCHIVED" ? "Archived: read-only" : "Suspended: read-only"}
            </span>
          ) : null}
        </p>
        <OrganizationTabs organization={organization} />
      </div>
      {children(organization, load)}
    </div>
  );
}

function OrganizationTabs({ organization }: { organization: Organization }) {
  const pathname = usePathname();
  const base = `/app/organizations/${organization.id}`;
  const tabs = [
    { href: base, label: "Overview" },
    { href: `${base}/members`, label: "Members" },
    { href: `${base}/projects`, label: "Projects" },
    { href: `${base}/analytics`, label: "Analytics" },
    ...(isAdmin(organization.role) ? [{ href: `${base}/audit`, label: "Audit log" }] : []),
  ];
  return (
    <nav aria-label="Team" className="flex flex-wrap gap-1 border-b">
      {tabs.map((tab) => {
        const current = pathname === tab.href;
        return (
          <Link
            key={tab.href}
            href={tab.href}
            aria-current={current ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${current ? "border-primary font-medium" : "text-muted-foreground border-transparent"}`}
          >
            {tab.label}
          </Link>
        );
      })}
    </nav>
  );
}
