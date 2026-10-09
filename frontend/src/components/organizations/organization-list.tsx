"use client";

import { Users } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { isApiError } from "@/lib/api/errors";
import type { Organization } from "@/lib/api/types";
import { createOrganization, listOrganizations, ROLE_LABELS } from "@/lib/organizations/client";

type State = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; organizations: Organization[] };

/** /app/organizations: the teams the user belongs to, and creating one (the creator becomes its owner). */
export function OrganizationList() {
  const router = useRouter();
  const [state, setState] = useState<State>({ status: "loading" });

  const load = useCallback(() => {
    listOrganizations()
      .then((page) => setState({ status: "ready", organizations: page.data }))
      .catch((error: unknown) => {
        if (isApiError(error) && error.status === 401) {
          router.replace("/login");
          router.refresh();
          return;
        }
        setState({ status: "error", error });
      });
  }, [router]);

  useEffect(() => load(), [load]);

  return (
    <div className="grid max-w-4xl gap-6">
      <div className="grid gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">Teams</h1>
        <p className="text-muted-foreground">
          Teams share projects and see team analytics. Your personal projects and your own CodeDNA stay yours.
        </p>
      </div>
      {state.status === "loading" ? (
        <p role="status" className="text-muted-foreground text-sm">
          Loading teams…
        </p>
      ) : state.status === "error" ? (
        <div className="grid max-w-md gap-3">
          <ApiErrorAlert error={state.error} />
          <Button variant="outline" className="w-fit" onClick={() => (setState({ status: "loading" }), load())}>
            Try again
          </Button>
        </div>
      ) : state.organizations.length === 0 ? (
        <div className="grid max-w-md justify-items-start gap-2 rounded-lg border border-dashed p-6" data-testid="organizations-empty">
          <Users className="text-muted-foreground size-6" aria-hidden="true" />
          <h2 className="font-medium">No teams yet</h2>
          <p className="text-muted-foreground text-sm">Create a team, or accept an invitation from a team admin.</p>
        </div>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2" data-testid="organizations">
          {state.organizations.map((organization) => (
            <li key={organization.id}>
              <Card>
                <CardHeader>
                  <CardTitle>
                    {organization.membership_status === "ACTIVE" ? (
                      <Link href={`/app/organizations/${organization.id}`} className="underline-offset-4 hover:underline">
                        {organization.name}
                      </Link>
                    ) : (
                      organization.name
                    )}
                  </CardTitle>
                  <CardDescription>
                    {ROLE_LABELS[organization.role]}
                    {organization.membership_status === "SUSPENDED" ? " · membership suspended" : ""}
                    {organization.status !== "ACTIVE" ? ` · ${organization.status.toLowerCase()}` : ""}
                  </CardDescription>
                </CardHeader>
                <CardContent className="text-muted-foreground text-sm">
                  {organization.member_count} active {organization.member_count === 1 ? "member" : "members"} · {organization.project_count}{" "}
                  {organization.project_count === 1 ? "project" : "projects"}
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}
      <CreateOrganizationForm onCreated={(organization) => router.push(`/app/organizations/${organization.id}`)} />
    </div>
  );
}

function CreateOrganizationForm({ onCreated }: { onCreated: (organization: Organization) => void }) {
  const [name, setName] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<unknown>(null);

  function submit(event: FormEvent) {
    event.preventDefault();
    if (name.trim() === "") {
      setError(null);
      return;
    }
    setSending(true);
    setError(null);
    createOrganization(name.trim())
      .then(onCreated)
      .catch((e: unknown) => setError(e))
      .finally(() => setSending(false));
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Create a team</h2>
        </CardTitle>
        <CardDescription>You become its owner. The team uses this installation&apos;s team plan and seat limit, shown on its page.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid max-w-md gap-3" noValidate>
          {error !== null ? <ApiErrorAlert error={error} /> : null}
          <FormField id="organization-name" label="Team name" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} required />
          <Button type="submit" className="w-fit" disabled={sending || name.trim() === ""}>
            {sending ? "Creating…" : "Create team"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
