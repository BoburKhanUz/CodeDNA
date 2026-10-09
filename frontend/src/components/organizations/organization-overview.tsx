"use client";

import { type FormEvent, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import { OrganizationFrame } from "@/components/organizations/organization-frame";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { Organization, OrganizationBilling } from "@/lib/api/types";
import { formatAmount } from "@/lib/billing/client";
import { archiveOrganization, getOrganizationBilling, isAdmin, renameOrganization } from "@/lib/organizations/client";
import { formatDate } from "@/lib/projects/format";

/** /app/organizations/[organization]: the team at a glance, and its settings for admins and the owner. */
export function OrganizationOverview({ organizationId }: { organizationId: string }) {
  return (
    <OrganizationFrame organizationId={organizationId} title="Overview">
      {(organization, reload) => <Overview organization={organization} reload={reload} />}
    </OrganizationFrame>
  );
}

function Overview({ organization, reload }: { organization: Organization; reload: () => void }) {
  const admin = isAdmin(organization.role);
  const active = organization.status === "ACTIVE";
  return (
    <div className="grid gap-6">
      <dl className="grid gap-4 sm:grid-cols-3" data-testid="organization-summary">
        <Stat label="Active members" value={organization.member_count} />
        <Stat label="Active projects" value={organization.project_count} />
        <Stat label="Created" value={formatDate(organization.created_at)} />
      </dl>
      {admin ? <BillingCard organizationId={organization.id} /> : null}
      {admin && active ? <RenameForm organization={organization} onRenamed={reload} /> : null}
      {organization.role === "OWNER" && active ? <ArchiveCard organization={organization} onArchived={reload} /> : null}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-lg border p-4">
      <dt className="text-muted-foreground text-sm">{label}</dt>
      <dd className="text-2xl font-semibold">{value}</dd>
    </div>
  );
}

function BillingCard({ organizationId }: { organizationId: string }) {
  const [billing, setBilling] = useState<OrganizationBilling | null>(null);
  const [error, setError] = useState<unknown>(null);
  useEffect(() => {
    getOrganizationBilling(organizationId).then(setBilling).catch(setError);
  }, [organizationId]);

  return (
    <Card data-testid="organization-billing">
      <CardHeader>
        <CardTitle>
          <h2>Team plan</h2>
        </CardTitle>
        <CardDescription>Team projects are measured against the team&apos;s plan, never a member&apos;s own plan.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-2 text-sm">
        {error !== null ? <ApiErrorAlert error={error} /> : null}
        {billing === null && error === null ? <p role="status">Loading…</p> : null}
        {billing !== null ? (
          <>
            <p>
              {billing.plan.name} plan · {billing.seats.used} of {billing.seats.limit} seats used
            </p>
            {billing.plan_source === "ENTERPRISE_LICENSE" ? (
              <p className="text-muted-foreground" data-testid="organization-billing-license">
                Plan and seats come from this installation&apos;s enterprise license.
              </p>
            ) : null}
            <ul className="text-muted-foreground grid gap-1">
              {billing.quotas
                .filter((q) => q.limit !== 0)
                .map((q) => (
                  <li key={q.key}>
                    {q.label}: {formatAmount(q.used, q.unit)}
                    {q.limit === null ? "" : ` of ${formatAmount(q.limit, q.unit)}`}
                  </li>
                ))}
            </ul>
            {billing.plan_source === "BILLING_ACCOUNT" ? <p className="text-muted-foreground">Team plans cannot be purchased yet.</p> : null}
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}

function RenameForm({ organization, onRenamed }: { organization: Organization; onRenamed: () => void }) {
  const [name, setName] = useState(organization.name);
  const [error, setError] = useState<unknown>(null);
  const [sending, setSending] = useState(false);

  function submit(event: FormEvent) {
    event.preventDefault();
    setSending(true);
    setError(null);
    renameOrganization(organization.id, name.trim())
      .then(onRenamed)
      .catch((e: unknown) => setError(e))
      .finally(() => setSending(false));
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Team name</h2>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid max-w-md gap-3" noValidate>
          {error !== null ? <ApiErrorAlert error={error} /> : null}
          <FormField id="rename" label="Name" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />
          <Button type="submit" className="w-fit" disabled={sending || name.trim() === "" || name.trim() === organization.name}>
            Save name
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function ArchiveCard({ organization, onArchived }: { organization: Organization; onArchived: () => void }) {
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Archive team</h2>
        </CardTitle>
        <CardDescription>
          Archiving is permanent. Nothing is deleted: projects and their history stay readable to members, but nothing can be changed.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {error !== null ? <ApiErrorAlert error={error} /> : null}
        {confirming ? (
          <div className="flex gap-2">
            <Button
              variant="destructive"
              onClick={() =>
                archiveOrganization(organization.id)
                  .then(onArchived)
                  .catch((e: unknown) => setError(e))
              }
            >
              Archive {organization.name}
            </Button>
            <Button variant="outline" onClick={() => setConfirming(false)}>
              Cancel
            </Button>
          </div>
        ) : (
          <Button variant="outline" className="w-fit" onClick={() => setConfirming(true)}>
            Archive team…
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
